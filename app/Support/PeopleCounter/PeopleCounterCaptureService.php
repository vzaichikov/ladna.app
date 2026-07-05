<?php

namespace App\Support\PeopleCounter;

use App\Enums\ScheduledClassStatus;
use App\Models\PeopleCounterSample;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Support\MediaMtxCameraGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class PeopleCounterCaptureService
{
    public function __construct(
        private readonly MediaMtxCameraGateway $gateway,
        private readonly PeopleCounterFrameCapture $frameCapture,
        private readonly PeopleCounterImageMasker $masker,
        private readonly PeopleCounterClient $client,
        private readonly PeopleCounterStudioHours $studioHours,
    ) {}

    /**
     * @param  callable(string, array<string, mixed>): void|null  $debug
     */
    public function captureDueClasses(?Carbon $now = null, int $limit = 100, ?callable $debug = null): int
    {
        $now ??= now();
        $latestStartAt = $now->copy()->subMinutes(5);
        $earliestEndAt = $now->copy()->addMinutes(5);
        $openAccountIds = $this->studioHours->openAccountIds($now, requireRtspCameras: true);
        $candidateCount = ScheduledClass::query()
            ->where('status', ScheduledClassStatus::Scheduled->value)
            ->where('starts_at', '<=', $latestStartAt)
            ->where('ends_at', '>=', $earliestEndAt)
            ->count();
        $classes = ScheduledClass::query()
            ->where('status', ScheduledClassStatus::Scheduled->value)
            ->where('starts_at', '<=', $latestStartAt)
            ->where('ends_at', '>=', $earliestEndAt)
            ->whereIn('account_id', $openAccountIds)
            ->whereHas('room', fn ($query) => $query
                ->active()
                ->rtspEnabled())
            ->with(['account:id,status,allow_rtsp_cameras,enable_people_counter,timezone,opening_hours', 'location:id,account_id,name,timezone', 'room'])
            ->orderBy('starts_at')
            ->limit($limit)
            ->get();

        $debug?->__invoke('capture.selection', [
            'now' => $now->toDateTimeString(),
            'latest_start_at' => $latestStartAt->toDateTimeString(),
            'earliest_end_at' => $earliestEndAt->toDateTimeString(),
            'limit' => $limit,
            'candidate_classes' => $candidateCount,
            'open_people_counter_studios' => count($openAccountIds),
            'eligible_classes' => $classes->count(),
        ]);

        $classes->each(fn (ScheduledClass $scheduledClass): PeopleCounterSample => $this->captureClass($scheduledClass, $now, $debug));

        return $classes->count();
    }

    /**
     * @param  callable(string, array<string, mixed>): void|null  $debug
     */
    public function captureClass(ScheduledClass $scheduledClass, ?Carbon $capturedAt = null, ?callable $debug = null): PeopleCounterSample
    {
        $capturedAt ??= now();
        $scheduledClass->loadMissing(['account', 'location', 'room']);
        $room = $scheduledClass->room;
        $displayTimezone = $scheduledClass->displayTimezone();
        $originalPath = $this->imagePath($scheduledClass->account_id, $scheduledClass->room_id, $capturedAt, 'original', $displayTimezone);
        $maskedPath = $this->imagePath($scheduledClass->account_id, $scheduledClass->room_id, $capturedAt, 'masked', $displayTimezone);
        $capturedFrame = null;
        $maskedFrame = null;

        $debug?->__invoke('capture.class.started', [
            'scheduled_class_id' => $scheduledClass->id,
            'account_id' => $scheduledClass->account_id,
            'location_id' => $scheduledClass->location_id,
            'room_id' => $scheduledClass->room_id,
            'display_timezone' => $displayTimezone,
            'captured_at_local' => $capturedAt->copy()->timezone($displayTimezone)->toDateTimeString(),
            'starts_at' => $scheduledClass->starts_at?->toDateTimeString(),
            'ends_at' => $scheduledClass->ends_at?->toDateTimeString(),
            'starts_at_local' => $scheduledClass->starts_at?->copy()->timezone($displayTimezone)->toDateTimeString(),
            'ends_at_local' => $scheduledClass->ends_at?->copy()->timezone($displayTimezone)->toDateTimeString(),
            'mask_polygons' => is_array($room?->people_counter_mask_polygons) ? count($room->people_counter_mask_polygons) : 0,
        ]);

        try {
            $this->gateway->ensurePath($room);
            $capturedFrame = $this->frameCapture->capture($this->gateway->pathName($room), $originalPath);
        } catch (Throwable $throwable) {
            return $this->failedSample($scheduledClass, $capturedAt, PeopleCounterSample::StatusCaptureFailed, $throwable, $capturedFrame, $maskedFrame, $debug);
        }

        try {
            $maskedFrame = $this->masker->mask($capturedFrame->path, $maskedPath, $room->people_counter_mask_polygons ?? []);
            $detection = $this->client->count(Storage::disk('local')->path($maskedFrame->path));

            $sample = PeopleCounterSample::create([
                'account_id' => $scheduledClass->account_id,
                'scheduled_class_id' => $scheduledClass->id,
                'location_id' => $scheduledClass->location_id,
                'room_id' => $scheduledClass->room_id,
                'captured_at' => $capturedAt,
                'status' => PeopleCounterSample::StatusSucceeded,
                'original_image_path' => $capturedFrame->path,
                'masked_image_path' => $maskedFrame->path,
                'image_width' => $maskedFrame->width,
                'image_height' => $maskedFrame->height,
                'detected_count' => $detection->count,
                'average_confidence' => $detection->averageConfidence,
                'detections' => $detection->detections,
                'response_payload' => $detection->payload,
            ]);

            $debug?->__invoke('capture.class.succeeded', [
                'scheduled_class_id' => $scheduledClass->id,
                'sample_id' => $sample->id,
                'detected_count' => $sample->detected_count,
                'average_confidence' => $sample->average_confidence,
                'image_width' => $sample->image_width,
                'image_height' => $sample->image_height,
                'original_image_path' => $sample->original_image_path,
                'masked_image_path' => $sample->masked_image_path,
            ]);

            return $sample;
        } catch (Throwable $throwable) {
            return $this->failedSample($scheduledClass, $capturedAt, PeopleCounterSample::StatusDetectionFailed, $throwable, $capturedFrame, $maskedFrame, $debug);
        }
    }

    public function captureCalibrationSnapshot(Room $room, ?Carbon $capturedAt = null): PeopleCounterCaptureResult
    {
        $capturedAt ??= now();
        $room->loadMissing(['account', 'location']);
        $displayTimezone = $this->roomTimezone($room);
        $this->gateway->ensurePath($room);
        $path = $this->imagePath($room->account_id, $room->id, $capturedAt, 'calibration', $displayTimezone);
        $result = $this->frameCapture->capture($this->gateway->pathName($room), $path);

        $oldPath = $room->people_counter_snapshot_path;
        $room->update([
            'people_counter_snapshot_path' => $result->path,
            'people_counter_snapshot_width' => $result->width,
            'people_counter_snapshot_height' => $result->height,
            'people_counter_snapshot_taken_at' => $capturedAt,
        ]);

        if (is_string($oldPath) && $oldPath !== $result->path) {
            Storage::disk('local')->delete($oldPath);
        }

        return $result;
    }

    private function failedSample(
        ScheduledClass $scheduledClass,
        Carbon $capturedAt,
        string $status,
        Throwable $throwable,
        ?PeopleCounterCaptureResult $capturedFrame,
        ?PeopleCounterCaptureResult $maskedFrame,
        ?callable $debug = null,
    ): PeopleCounterSample {
        $sample = PeopleCounterSample::create([
            'account_id' => $scheduledClass->account_id,
            'scheduled_class_id' => $scheduledClass->id,
            'location_id' => $scheduledClass->location_id,
            'room_id' => $scheduledClass->room_id,
            'captured_at' => $capturedAt,
            'status' => $status,
            'failure_reason' => Str::limit($throwable->getMessage(), 2000),
            'original_image_path' => $capturedFrame?->path,
            'masked_image_path' => $maskedFrame?->path,
            'image_width' => $maskedFrame?->width ?? $capturedFrame?->width,
            'image_height' => $maskedFrame?->height ?? $capturedFrame?->height,
        ]);

        $debug?->__invoke('capture.class.failed', [
            'scheduled_class_id' => $scheduledClass->id,
            'sample_id' => $sample->id,
            'status' => $sample->status,
            'failure_reason' => $sample->failure_reason,
            'original_image_path' => $sample->original_image_path,
            'masked_image_path' => $sample->masked_image_path,
        ]);

        return $sample;
    }

    private function imagePath(int $accountId, ?int $roomId, Carbon $capturedAt, string $variant, ?string $timezone = null): string
    {
        $displayCapturedAt = $capturedAt->copy()->timezone($timezone ?: config('app.timezone'));
        $timestamp = $displayCapturedAt->format('YmdHis');
        $suffix = Str::lower(Str::random(8));

        return 'people-counter/a'.$accountId.'/r'.($roomId ?? 0).'/'.$displayCapturedAt->format('Y/m/d').'/'.$timestamp.'-'.$variant.'-'.$suffix.'.jpg';
    }

    private function roomTimezone(Room $room): string
    {
        return $room->location?->timezone
            ?? $room->account?->timezone
            ?? config('app.timezone');
    }
}

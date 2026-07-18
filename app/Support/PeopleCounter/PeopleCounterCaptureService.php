<?php

namespace App\Support\PeopleCounter;

use App\Models\Account;
use App\Models\PeopleCounterSample;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\UnknownPresenceInterval;
use App\Support\MediaMtxCameraGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
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
        $classCount = $this->captureDueClassSamples($now, $limit, $debug);
        $unknownPresenceCount = $this->captureUnknownPresenceSamples($now, $limit, $debug);

        return $classCount + $unknownPresenceCount;
    }

    /**
     * @param  callable(string, array<string, mixed>): void|null  $debug
     */
    private function captureDueClassSamples(Carbon $now, int $limit, ?callable $debug = null): int
    {
        $latestStartAt = $now->copy()->subMinutes(PeopleCounterSamplingWindow::StartBufferMinutes);
        $earliestEndAt = $now->copy()->addMinutes(PeopleCounterSamplingWindow::EndBufferMinutes);
        $openAccountIds = $this->studioHours->openAccountIds($now, requireRtspCameras: true);
        $candidateCount = ScheduledClass::query()
            ->whereHas('account', fn ($query) => $query->operational())
            ->peopleCounterTrackable()
            ->where('starts_at', '<=', $latestStartAt)
            ->where('ends_at', '>=', $earliestEndAt)
            ->count();
        $classes = ScheduledClass::query()
            ->peopleCounterTrackable()
            ->where('starts_at', '<=', $latestStartAt)
            ->where('ends_at', '>=', $earliestEndAt)
            ->whereIn('account_id', $openAccountIds)
            ->whereHas('room', fn ($query) => $query
                ->active()
                ->rtspEnabled())
            ->with(['account:id,status,mode,allow_rtsp_cameras,enable_people_counter,timezone,opening_hours', 'location:id,account_id,name,timezone', 'room'])
            ->orderBy('starts_at')
            ->limit($limit)
            ->get();

        $debug?->__invoke('capture.selection', [
            'now' => $now->toDateTimeString(),
            'latest_start_at' => $latestStartAt->toDateTimeString(),
            'earliest_end_at' => $earliestEndAt->toDateTimeString(),
            'start_buffer_minutes' => PeopleCounterSamplingWindow::StartBufferMinutes,
            'end_buffer_minutes' => PeopleCounterSamplingWindow::EndBufferMinutes,
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
    private function captureUnknownPresenceSamples(Carbon $now, int $limit, ?callable $debug = null): int
    {
        $peopleCounterAccountIds = $this->peopleCounterAccountIds(requireRtspCameras: true);
        $protectedAfter = $now->copy()->subMinutes(PeopleCounterSamplingWindow::UnknownPresencePostClassGraceMinutes);
        $candidateCount = Room::query()
            ->whereIn('account_id', $peopleCounterAccountIds)
            ->active()
            ->rtspEnabled()
            ->count();
        $rooms = Room::query()
            ->whereIn('account_id', $peopleCounterAccountIds)
            ->active()
            ->rtspEnabled()
            ->whereDoesntHave('scheduledClasses', fn ($query) => $query
                ->peopleCounterTrackable()
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>=', $protectedAfter))
            ->with(['account:id,status,mode,allow_rtsp_cameras,enable_people_counter,timezone,opening_hours', 'location:id,account_id,name,timezone'])
            ->orderBy('account_id')
            ->orderBy('location_id')
            ->orderBy('name')
            ->limit($limit)
            ->get();

        $debug?->__invoke('capture.unknown.selection', [
            'now' => $now->toDateTimeString(),
            'post_class_grace_minutes' => PeopleCounterSamplingWindow::UnknownPresencePostClassGraceMinutes,
            'merge_gap_minutes' => PeopleCounterSamplingWindow::UnknownPresenceMergeGapMinutes,
            'protected_after_or_at' => $protectedAfter->toDateTimeString(),
            'limit' => $limit,
            'candidate_rooms' => $candidateCount,
            'people_counter_studios' => count($peopleCounterAccountIds),
            'whole_day' => true,
            'eligible_rooms' => $rooms->count(),
        ]);

        return $rooms
            ->map(fn (Room $room): ?PeopleCounterSample => $this->captureUnknownPresence($room, $now, $debug))
            ->filter()
            ->count();
    }

    /**
     * @return array<int, int>
     */
    private function peopleCounterAccountIds(bool $requireRtspCameras = false): array
    {
        return Account::query()
            ->operational()
            ->active()
            ->where('enable_people_counter', true)
            ->when(
                $requireRtspCameras,
                fn ($query) => $query->where('allow_rtsp_cameras', true),
            )
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  callable(string, array<string, mixed>): void|null  $debug
     */
    public function captureClass(ScheduledClass $scheduledClass, ?Carbon $capturedAt = null, ?callable $debug = null): PeopleCounterSample
    {
        $capturedAt ??= now();
        $scheduledClass->loadMissing(['account', 'location', 'room']);
        $this->assertOperationalAccount($scheduledClass->account);
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
            $capturedFrame = $this->frameCapture->capture($this->gateway->pathName($room), $originalPath, $room->peopleCounterCaptureDelaySeconds());
        } catch (Throwable $throwable) {
            return $this->failedSample($scheduledClass, $capturedAt, PeopleCounterSample::StatusCaptureFailed, $throwable, $capturedFrame, $maskedFrame, $debug);
        }

        try {
            $maskedFrame = $this->masker->mask($capturedFrame->path, $maskedPath, $room->people_counter_mask_polygons ?? []);
            $detection = $this->client->count(Storage::disk('local')->path($maskedFrame->path));
            $this->deleteFrame($maskedFrame);

            $sample = PeopleCounterSample::create([
                'account_id' => $scheduledClass->account_id,
                'scheduled_class_id' => $scheduledClass->id,
                'location_id' => $scheduledClass->location_id,
                'room_id' => $scheduledClass->room_id,
                'captured_at' => $capturedAt,
                'status' => PeopleCounterSample::StatusSucceeded,
                'original_image_path' => $capturedFrame->path,
                'masked_image_path' => null,
                'image_width' => $capturedFrame->width,
                'image_height' => $capturedFrame->height,
                'detected_count' => $detection->count,
                'average_confidence' => $detection->averageConfidence,
                'detections' => $detection->detections,
                'response_payload' => $detection->payload,
            ]);

            $this->prunePreviousEmptySnapshots($room, $sample);

            $debug?->__invoke('capture.class.succeeded', [
                'scheduled_class_id' => $scheduledClass->id,
                'sample_id' => $sample->id,
                'detected_count' => $sample->detected_count,
                'average_confidence' => $sample->average_confidence,
                'image_width' => $sample->image_width,
                'image_height' => $sample->image_height,
                'stored_original_image' => $sample->original_image_path !== null,
                'stored_masked_image' => $sample->masked_image_path !== null,
            ]);

            return $sample;
        } catch (Throwable $throwable) {
            return $this->failedSample($scheduledClass, $capturedAt, PeopleCounterSample::StatusDetectionFailed, $throwable, $capturedFrame, $maskedFrame, $debug);
        }
    }

    /**
     * @param  callable(string, array<string, mixed>): void|null  $debug
     */
    public function captureUnknownPresence(Room $room, ?Carbon $capturedAt = null, ?callable $debug = null): ?PeopleCounterSample
    {
        $capturedAt ??= now();
        $room->loadMissing(['account', 'location']);
        $this->assertOperationalAccount($room->account);
        $displayTimezone = $this->roomTimezone($room);
        $originalPath = $this->imagePath($room->account_id, $room->id, $capturedAt, 'unknown-original', $displayTimezone);
        $maskedPath = $this->imagePath($room->account_id, $room->id, $capturedAt, 'unknown-masked', $displayTimezone);
        $capturedFrame = null;
        $maskedFrame = null;

        $debug?->__invoke('capture.unknown.started', [
            'account_id' => $room->account_id,
            'location_id' => $room->location_id,
            'room_id' => $room->id,
            'display_timezone' => $displayTimezone,
            'captured_at_local' => $capturedAt->copy()->timezone($displayTimezone)->toDateTimeString(),
            'mask_polygons' => is_array($room->people_counter_mask_polygons) ? count($room->people_counter_mask_polygons) : 0,
        ]);

        try {
            $this->gateway->ensurePath($room);
            $capturedFrame = $this->frameCapture->capture($this->gateway->pathName($room), $originalPath, $room->peopleCounterCaptureDelaySeconds());
            $maskedFrame = $this->masker->mask($capturedFrame->path, $maskedPath, $room->people_counter_mask_polygons ?? []);
            $detection = $this->client->count(Storage::disk('local')->path($maskedFrame->path));
            $this->deleteFrame($maskedFrame);

            if ($detection->count < 1) {
                $sample = $this->recordEmptyDashboardSample($room, $capturedAt, $capturedFrame, $detection);
                $debug?->__invoke('capture.unknown.empty', [
                    'account_id' => $room->account_id,
                    'location_id' => $room->location_id,
                    'room_id' => $room->id,
                    'sample_id' => $sample->id,
                    'detected_count' => $detection->count,
                    'stored_original_image' => $sample->original_image_path !== null,
                ]);

                return $sample;
            }

            $sample = $this->recordUnknownPresenceSample($room, $capturedAt, $capturedFrame, $detection);

            $debug?->__invoke('capture.unknown.succeeded', [
                'account_id' => $room->account_id,
                'location_id' => $room->location_id,
                'room_id' => $room->id,
                'interval_id' => $sample->unknown_presence_interval_id,
                'sample_id' => $sample->id,
                'detected_count' => $sample->detected_count,
                'average_confidence' => $sample->average_confidence,
                'stored_original_image' => $sample->original_image_path !== null,
            ]);

            return $sample;
        } catch (Throwable $throwable) {
            $this->deleteFrame($capturedFrame);
            $this->deleteFrame($maskedFrame);
            $debug?->__invoke('capture.unknown.failed', [
                'account_id' => $room->account_id,
                'location_id' => $room->location_id,
                'room_id' => $room->id,
                'failure_reason' => Str::limit($throwable->getMessage(), 2000),
            ]);

            return null;
        }
    }

    public function captureCalibrationSnapshot(Room $room, ?Carbon $capturedAt = null): PeopleCounterCaptureResult
    {
        $capturedAt ??= now();
        $room->loadMissing(['account', 'location']);
        $this->assertOperationalAccount($room->account);
        $displayTimezone = $this->roomTimezone($room);
        $this->gateway->ensurePath($room);
        $path = $this->imagePath($room->account_id, $room->id, $capturedAt, 'calibration', $displayTimezone);
        $result = $this->frameCapture->capture($this->gateway->pathName($room), $path, $room->peopleCounterCaptureDelaySeconds());

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
        $this->deleteFrame($capturedFrame);
        $this->deleteFrame($maskedFrame);

        $sample = PeopleCounterSample::create([
            'account_id' => $scheduledClass->account_id,
            'scheduled_class_id' => $scheduledClass->id,
            'location_id' => $scheduledClass->location_id,
            'room_id' => $scheduledClass->room_id,
            'captured_at' => $capturedAt,
            'status' => $status,
            'failure_reason' => Str::limit($throwable->getMessage(), 2000),
            'original_image_path' => null,
            'masked_image_path' => null,
            'image_width' => null,
            'image_height' => null,
        ]);

        $debug?->__invoke('capture.class.failed', [
            'scheduled_class_id' => $scheduledClass->id,
            'sample_id' => $sample->id,
            'status' => $sample->status,
            'failure_reason' => $sample->failure_reason,
            'stored_original_image' => $sample->original_image_path !== null,
            'stored_masked_image' => $sample->masked_image_path !== null,
        ]);

        return $sample;
    }

    private function recordUnknownPresenceSample(
        Room $room,
        Carbon $capturedAt,
        PeopleCounterCaptureResult $capturedFrame,
        PeopleCounterDetectionResult $detection,
    ): PeopleCounterSample {
        return DB::transaction(function () use ($room, $capturedAt, $capturedFrame, $detection): PeopleCounterSample {
            $interval = UnknownPresenceInterval::query()
                ->where('account_id', $room->account_id)
                ->where('room_id', $room->id)
                ->where('ended_at', '>=', $capturedAt->copy()->subMinutes(PeopleCounterSamplingWindow::UnknownPresenceMergeGapMinutes))
                ->orderByDesc('ended_at')
                ->lockForUpdate()
                ->first();

            if (! $interval) {
                $interval = UnknownPresenceInterval::create([
                    'account_id' => $room->account_id,
                    'location_id' => $room->location_id,
                    'room_id' => $room->id,
                    'started_at' => $capturedAt,
                    'ended_at' => $capturedAt,
                    'sample_count' => 0,
                    'peak_detected_count' => 0,
                ]);
            }

            $interval->update([
                'ended_at' => $capturedAt->greaterThan($interval->ended_at) ? $capturedAt : $interval->ended_at,
                'sample_count' => $interval->sample_count + 1,
                'peak_detected_count' => max($interval->peak_detected_count, $detection->count),
            ]);

            $sample = PeopleCounterSample::create([
                'account_id' => $room->account_id,
                'scheduled_class_id' => null,
                'unknown_presence_interval_id' => $interval->id,
                'location_id' => $room->location_id,
                'room_id' => $room->id,
                'captured_at' => $capturedAt,
                'status' => PeopleCounterSample::StatusSucceeded,
                'original_image_path' => $capturedFrame->path,
                'masked_image_path' => null,
                'image_width' => $capturedFrame->width,
                'image_height' => $capturedFrame->height,
                'detected_count' => $detection->count,
                'average_confidence' => $detection->averageConfidence,
                'detections' => $detection->detections,
                'response_payload' => $detection->payload,
            ]);

            $this->prunePreviousEmptySnapshots($room, $sample);

            return $sample;
        });
    }

    private function recordEmptyDashboardSample(
        Room $room,
        Carbon $capturedAt,
        PeopleCounterCaptureResult $capturedFrame,
        PeopleCounterDetectionResult $detection,
    ): PeopleCounterSample {
        $sample = PeopleCounterSample::create([
            'account_id' => $room->account_id,
            'scheduled_class_id' => null,
            'unknown_presence_interval_id' => null,
            'location_id' => $room->location_id,
            'room_id' => $room->id,
            'captured_at' => $capturedAt,
            'status' => PeopleCounterSample::StatusSucceeded,
            'original_image_path' => $capturedFrame->path,
            'masked_image_path' => null,
            'image_width' => $capturedFrame->width,
            'image_height' => $capturedFrame->height,
            'detected_count' => $detection->count,
            'average_confidence' => $detection->averageConfidence,
            'detections' => $detection->detections,
            'response_payload' => $detection->payload,
        ]);

        $this->prunePreviousEmptySnapshots($room, $sample);

        return $sample;
    }

    private function prunePreviousEmptySnapshots(Room $room, PeopleCounterSample $currentSample): void
    {
        PeopleCounterSample::query()
            ->where('account_id', $room->account_id)
            ->where('room_id', $room->id)
            ->whereKeyNot($currentSample->id)
            ->where('status', PeopleCounterSample::StatusSucceeded)
            ->where('detected_count', 0)
            ->where(function ($query): void {
                $query
                    ->whereNotNull('original_image_path')
                    ->orWhere(function ($query): void {
                        $query
                            ->whereNull('scheduled_class_id')
                            ->whereNull('unknown_presence_interval_id');
                    });
            })
            ->each(function (PeopleCounterSample $sample): void {
                if (is_string($sample->original_image_path)) {
                    Storage::disk('local')->delete($sample->original_image_path);
                }

                if ($sample->scheduled_class_id === null && $sample->unknown_presence_interval_id === null) {
                    $sample->delete();

                    return;
                }

                $sample->update([
                    'original_image_path' => null,
                    'image_width' => null,
                    'image_height' => null,
                ]);
            });
    }

    private function deleteFrame(?PeopleCounterCaptureResult $frame): void
    {
        if ($frame) {
            Storage::disk('local')->delete($frame->path);
        }
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

    private function assertOperationalAccount(?Account $account): void
    {
        if (! $account || $account->isReadOnlyDemo()) {
            throw new RuntimeException('People counter capture is unavailable for synthetic demo accounts.');
        }
    }
}

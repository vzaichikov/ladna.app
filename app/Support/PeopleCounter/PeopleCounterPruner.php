<?php

namespace App\Support\PeopleCounter;

use App\Models\PeopleCounterSample;
use App\Models\Room;
use App\Models\ScheduledClassPeopleCount;
use App\Models\UnknownPresenceInterval;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PeopleCounterPruner
{
    private const ImageRoot = 'people-counter';

    /**
     * @param  callable(string, array<string, mixed>): void|null  $debug
     */
    public function prune(?Carbon $now = null, ?callable $debug = null): int
    {
        $now ??= now();
        $cutoff = $now->copy()->subDays($this->retentionDays());
        $deleted = 0;
        $disk = Storage::disk('local');
        $oldSamplesCount = PeopleCounterSample::query()
            ->where('captured_at', '<', $cutoff)
            ->count();
        $oldSummariesCount = ScheduledClassPeopleCount::query()
            ->where('summarized_at', '<', $cutoff)
            ->count();
        $oldUnknownIntervalsCount = UnknownPresenceInterval::query()
            ->where('ended_at', '<', $cutoff)
            ->count();
        $staleSnapshotsCount = Room::query()
            ->whereNotNull('people_counter_snapshot_path')
            ->where('people_counter_snapshot_taken_at', '<', $cutoff)
            ->count();

        $debug?->__invoke('prune.started', [
            'now' => $now->toDateTimeString(),
            'retention_days' => $this->retentionDays(),
            'cutoff' => $cutoff->toDateTimeString(),
            'old_samples' => $oldSamplesCount,
            'old_summaries' => $oldSummariesCount,
            'old_unknown_presence_intervals' => $oldUnknownIntervalsCount,
            'stale_room_snapshots' => $staleSnapshotsCount,
        ]);

        PeopleCounterSample::query()
            ->where('captured_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(200, function ($samples) use ($debug, $disk, &$deleted): void {
                foreach ($samples as $sample) {
                    $this->deleteImage($disk, $sample->original_image_path);
                    $this->deleteImage($disk, $sample->masked_image_path);
                    $sample->delete();
                    $deleted++;
                    $debug?->__invoke('prune.sample.deleted', [
                        'sample_id' => $sample->id,
                        'scheduled_class_id' => $sample->scheduled_class_id,
                        'captured_at' => $sample->captured_at?->toDateTimeString(),
                        'status' => $sample->status,
                        'original_image_path' => $sample->original_image_path,
                        'masked_image_path' => $sample->masked_image_path,
                    ]);
                }
            });

        $deletedSummaries = ScheduledClassPeopleCount::query()
            ->where('summarized_at', '<', $cutoff)
            ->delete();
        $deleted += $deletedSummaries;

        $debug?->__invoke('prune.summaries.deleted', [
            'count' => $deletedSummaries,
        ]);

        $deletedUnknownIntervals = UnknownPresenceInterval::query()
            ->where('ended_at', '<', $cutoff)
            ->delete();
        $deleted += $deletedUnknownIntervals;

        $debug?->__invoke('prune.unknown_presence_intervals.deleted', [
            'count' => $deletedUnknownIntervals,
        ]);

        Room::query()
            ->whereNotNull('people_counter_snapshot_path')
            ->where(function ($query) use ($cutoff): void {
                $query
                    ->where('people_counter_snapshot_taken_at', '<', $cutoff)
                    ->orWhereNull('people_counter_snapshot_taken_at');
            })
            ->orderBy('id')
            ->chunkById(200, function ($rooms) use ($debug, $disk): void {
                foreach ($rooms as $room) {
                    $snapshotTakenAt = $room->people_counter_snapshot_taken_at;
                    $this->deleteImage($disk, $room->people_counter_snapshot_path);
                    $room->update([
                        'people_counter_snapshot_path' => null,
                        'people_counter_snapshot_width' => null,
                        'people_counter_snapshot_height' => null,
                        'people_counter_snapshot_taken_at' => null,
                    ]);
                    $debug?->__invoke('prune.room_snapshot.deleted', [
                        'room_id' => $room->id,
                        'account_id' => $room->account_id,
                        'snapshot_taken_at' => $snapshotTakenAt?->toDateTimeString(),
                    ]);
                }
            });

        $deletedOrphanImages = $this->pruneOrphanedImages($disk, $cutoff);

        $debug?->__invoke('prune.orphan_images.deleted', [
            'count' => $deletedOrphanImages,
        ]);

        $debug?->__invoke('prune.finished', [
            'deleted_records' => $deleted,
            'deleted_orphan_images' => $deletedOrphanImages,
        ]);

        return $deleted;
    }

    private function retentionDays(): int
    {
        return max(1, (int) config('services.people_counter.retention_days', 14));
    }

    private function deleteImage(FilesystemAdapter $disk, ?string $path): void
    {
        if (is_string($path) && $path !== '') {
            $disk->delete($path);
        }
    }

    private function pruneOrphanedImages(FilesystemAdapter $disk, Carbon $cutoff): int
    {
        if (! $disk->exists(self::ImageRoot)) {
            return 0;
        }

        $deleted = 0;
        $cutoffTimestamp = $cutoff->getTimestamp();

        collect($disk->allFiles(self::ImageRoot))
            ->filter(fn (string $path): bool => $this->isOlderThan($disk, $path, $cutoffTimestamp))
            ->chunk(200)
            ->each(function ($paths) use ($disk, &$deleted): void {
                $pathList = $paths->values()->all();
                $referencedPaths = $this->referencedImagePaths($pathList);

                foreach ($pathList as $path) {
                    if (isset($referencedPaths[$path])) {
                        continue;
                    }

                    if ($disk->delete($path)) {
                        $deleted++;
                    }
                }
            });

        return $deleted;
    }

    private function isOlderThan(FilesystemAdapter $disk, string $path, int $cutoffTimestamp): bool
    {
        try {
            return $disk->lastModified($path) < $cutoffTimestamp;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<string, true>
     */
    private function referencedImagePaths(array $paths): array
    {
        $samplePaths = PeopleCounterSample::query()
            ->where(function ($query) use ($paths): void {
                $query
                    ->whereIn('original_image_path', $paths)
                    ->orWhereIn('masked_image_path', $paths);
            })
            ->get(['original_image_path', 'masked_image_path'])
            ->flatMap(fn (PeopleCounterSample $sample): array => [
                $sample->original_image_path,
                $sample->masked_image_path,
            ])
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->all();

        $roomPaths = Room::query()
            ->whereIn('people_counter_snapshot_path', $paths)
            ->pluck('people_counter_snapshot_path')
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->all();

        return collect([...$samplePaths, ...$roomPaths])
            ->unique()
            ->mapWithKeys(fn (string $path): array => [$path => true])
            ->all();
    }
}

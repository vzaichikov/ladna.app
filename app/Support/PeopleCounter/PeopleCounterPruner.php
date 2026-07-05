<?php

namespace App\Support\PeopleCounter;

use App\Models\PeopleCounterSample;
use App\Models\Room;
use App\Models\ScheduledClassPeopleCount;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class PeopleCounterPruner
{
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

        Room::query()
            ->whereNotNull('people_counter_snapshot_path')
            ->where('people_counter_snapshot_taken_at', '<', $cutoff)
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

        $debug?->__invoke('prune.finished', [
            'deleted_records' => $deleted,
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
}

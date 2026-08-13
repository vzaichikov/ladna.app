<?php

namespace App\Actions\Festivals;

use App\Models\FestivalTimeline;
use App\Models\FestivalTimelineItem;
use App\Models\User;
use App\Support\Festivals\FestivalTimelineEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReorderFestivalTimeline
{
    public function __construct(
        private readonly FestivalActivityRecorder $activity,
        private readonly FestivalTimelineEngine $engine,
        private readonly ScheduleFestivalTimelineAdvance $scheduleAdvance,
    ) {}

    /**
     * @param  list<int>  $itemIds
     * @return array<int, int>
     */
    public function execute(FestivalTimeline $timeline, array $itemIds, User $actor): array
    {
        return DB::transaction(function () use ($timeline, $itemIds, $actor): array {
            $lockedTimeline = FestivalTimeline::query()->whereKey($timeline->id)->lockForUpdate()->firstOrFail();
            $items = FestivalTimelineItem::query()
                ->where('festival_timeline_id', $lockedTimeline->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $this->validateExactSet($items, $itemIds);
            $sortOrders = [];

            foreach ($itemIds as $index => $itemId) {
                $item = $items->get($itemId);
                $sortOrder = ($index + 1) * 10;
                $item->forceFill(['sort_order' => $sortOrder])->save();
                $sortOrders[$item->id] = $sortOrder;
            }

            $orderedItems = $items->sortBy(fn (FestivalTimelineItem $item): string => sprintf('%010d:%020d', $item->sort_order, $item->id))->values();

            if ($lockedTimeline->started_at && ! $lockedTimeline->completed_at) {
                if ($lockedTimeline->active_item_id) {
                    $this->engine->recountAfterActive($lockedTimeline, $orderedItems);
                } else {
                    $this->engine->recountAfterGap(
                        $lockedTimeline,
                        $orderedItems,
                        $lockedTimeline->next_transition_at ?? CarbonImmutable::now(),
                    );
                }

                $this->scheduleAdvance->execute($lockedTimeline);
            }

            $lockedTimeline->forceFill(['updated_by' => $actor->id])->save();
            $this->activity->record($lockedTimeline, 'timeline.reordered', $lockedTimeline->edition, $actor, [
                'item_ids' => $itemIds,
            ]);

            return $sortOrders;
        }, 3);
    }

    /** @param Collection<int, FestivalTimelineItem> $items */
    private function validateExactSet(Collection $items, array $itemIds): void
    {
        $expectedIds = $items->keys()->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $receivedIds = collect($itemIds)->map(fn ($id): int => (int) $id)->sort()->values()->all();

        if ($expectedIds !== $receivedIds) {
            throw ValidationException::withMessages(['items' => __('app.festival_timeline_order_invalid')]);
        }
    }
}

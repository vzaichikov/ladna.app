<?php

namespace App\Actions\Festivals;

use App\Models\FestivalTimeline;
use App\Models\FestivalTimelineItem;
use App\Models\User;
use App\Support\Festivals\FestivalTimelineEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ToggleFestivalTimelineItem
{
    public function __construct(
        private readonly FestivalActivityRecorder $activity,
        private readonly FestivalTimelineEngine $engine,
        private readonly ScheduleFestivalTimelineAdvance $scheduleAdvance,
    ) {}

    public function execute(FestivalTimeline $timeline, FestivalTimelineItem $item, User $actor): FestivalTimelineItem
    {
        return DB::transaction(function () use ($timeline, $item, $actor): FestivalTimelineItem {
            $lockedTimeline = FestivalTimeline::query()->whereKey($timeline->id)->lockForUpdate()->firstOrFail();
            $items = FestivalTimelineItem::query()
                ->where('festival_timeline_id', $lockedTimeline->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lockedItem = $items->firstWhere('id', $item->id);
            abort_unless($lockedItem instanceof FestivalTimelineItem, 404);
            $wasEnabled = $lockedItem->is_enabled;
            $gapBoundary = $lockedTimeline->next_transition_at?->toImmutable();
            $lockedItem->forceFill(['is_enabled' => ! $wasEnabled])->save();
            $now = CarbonImmutable::now();

            if ($lockedTimeline->started_at) {
                if ($wasEnabled && $lockedTimeline->active_item_id === $lockedItem->id) {
                    $nextItem = $items
                        ->skipUntil(fn (FestivalTimelineItem $candidate): bool => $candidate->id === $lockedItem->id)
                        ->skip(1)
                        ->first(fn (FestivalTimelineItem $candidate): bool => $candidate->is_enabled);

                    if ($nextItem instanceof FestivalTimelineItem) {
                        $nextItem->forceFill([
                            'planned_starts_at' => $now,
                            'planned_ends_at' => $now->addSeconds($nextItem->duration_seconds),
                        ])->save();
                        $lockedTimeline->forceFill([
                            'active_item_id' => $nextItem->id,
                            'completed_at' => null,
                            'next_transition_at' => $nextItem->planned_ends_at,
                        ])->save();
                        $this->engine->recountAfterActive($lockedTimeline, $items);
                    } else {
                        $lockedTimeline->forceFill([
                            'active_item_id' => null,
                            'last_finished_item_id' => $items->where('is_enabled', true)->last()?->id,
                            'completed_at' => $now,
                            'next_transition_at' => null,
                        ])->save();
                    }
                } elseif ($lockedTimeline->active_item_id) {
                    $this->engine->recountAfterActive($lockedTimeline, $items);
                } elseif (! $lockedTimeline->completed_at) {
                    $this->engine->recountAfterGap($lockedTimeline, $items, $gapBoundary ?? $now);
                }
            }

            $lockedTimeline->forceFill(['updated_by' => $actor->id])->save();
            $this->scheduleAdvance->execute($lockedTimeline);
            $this->activity->record($lockedItem, $lockedItem->is_enabled ? 'timeline.item_enabled' : 'timeline.item_disabled', $lockedTimeline->edition, $actor, [
                'active_item_id' => $lockedTimeline->active_item_id,
            ]);

            return $lockedItem->refresh();
        }, 3);
    }
}

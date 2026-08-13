<?php

namespace App\Actions\Festivals;

use App\Models\FestivalTimeline;
use App\Models\FestivalTimelineItem;
use App\Models\User;
use App\Support\Festivals\FestivalTimelineEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivateFestivalTimelineItem
{
    public function __construct(
        private readonly FestivalActivityRecorder $activity,
        private readonly FestivalTimelineEngine $engine,
        private readonly ScheduleFestivalTimelineAdvance $scheduleAdvance,
    ) {}

    public function execute(FestivalTimeline $timeline, FestivalTimelineItem $item, User $actor): FestivalTimeline
    {
        return DB::transaction(function () use ($timeline, $item, $actor): FestivalTimeline {
            $lockedTimeline = FestivalTimeline::query()->whereKey($timeline->id)->lockForUpdate()->firstOrFail();

            if (! $lockedTimeline->started_at) {
                throw ValidationException::withMessages(['timeline' => __('app.festival_timeline_not_started')]);
            }

            $items = FestivalTimelineItem::query()
                ->where('festival_timeline_id', $lockedTimeline->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lockedItem = $items->firstWhere('id', $item->id);

            if (! $lockedItem instanceof FestivalTimelineItem || ! $lockedItem->is_enabled) {
                throw ValidationException::withMessages(['timeline' => __('app.festival_timeline_item_disabled')]);
            }

            $now = CarbonImmutable::now();
            $previousActiveItemId = $lockedTimeline->active_item_id;
            $lockedItem->forceFill([
                'planned_starts_at' => $now,
                'planned_ends_at' => $now->addSeconds($lockedItem->duration_seconds),
            ])->save();
            $lockedTimeline->forceFill([
                'active_item_id' => $lockedItem->id,
                'last_finished_item_id' => $items->where('is_enabled', true)->takeUntil(fn (FestivalTimelineItem $candidate): bool => $candidate->id === $lockedItem->id)->last()?->id,
                'completed_at' => null,
                'next_transition_at' => $lockedItem->planned_ends_at,
                'updated_by' => $actor->id,
            ])->save();
            $this->engine->recountAfterActive($lockedTimeline, $items);
            $this->scheduleAdvance->execute($lockedTimeline);
            $this->activity->record($lockedItem, 'timeline.item_activated', $lockedTimeline->edition, $actor, [
                'previous_active_item_id' => $previousActiveItemId,
                'starts_at' => $lockedItem->planned_starts_at->toISOString(),
                'ends_at' => $lockedItem->planned_ends_at->toISOString(),
            ]);

            return $lockedTimeline->refresh();
        }, 3);
    }
}

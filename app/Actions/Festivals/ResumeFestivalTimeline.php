<?php

namespace App\Actions\Festivals;

use App\Models\FestivalTimeline;
use App\Models\FestivalTimelineItem;
use App\Models\User;
use App\Support\Festivals\FestivalTimelineEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResumeFestivalTimeline
{
    public function __construct(
        private readonly FestivalActivityRecorder $activity,
        private readonly FestivalTimelineEngine $engine,
        private readonly ScheduleFestivalTimelineAdvance $scheduleAdvance,
    ) {}

    public function execute(FestivalTimeline $timeline, User $actor): FestivalTimeline
    {
        return DB::transaction(function () use ($timeline, $actor): FestivalTimeline {
            $lockedTimeline = FestivalTimeline::query()->whereKey($timeline->id)->lockForUpdate()->firstOrFail();

            if (! $lockedTimeline->started_at || ! $lockedTimeline->paused_at || $lockedTimeline->completed_at) {
                throw ValidationException::withMessages(['timeline' => __('app.festival_timeline_resume_invalid')]);
            }

            $items = FestivalTimelineItem::query()
                ->where('festival_timeline_id', $lockedTimeline->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $now = CarbonImmutable::now();
            $activeItem = $items->firstWhere('id', $lockedTimeline->active_item_id);

            if (! $activeItem instanceof FestivalTimelineItem) {
                $this->engine->synchronize($lockedTimeline, $items, $now);
                $activeItem = $items->firstWhere('id', $lockedTimeline->active_item_id);
            }

            if ($activeItem instanceof FestivalTimelineItem) {
                $activeItem->forceFill([
                    'planned_starts_at' => $now,
                    'planned_ends_at' => $now->addSeconds($activeItem->duration_seconds),
                ])->save();
                $lockedTimeline->forceFill(['next_transition_at' => $activeItem->planned_ends_at])->save();
                $this->engine->recountAfterActive($lockedTimeline, $items);
            }

            $lockedTimeline->forceFill(['paused_at' => null, 'updated_by' => $actor->id])->save();
            $this->scheduleAdvance->execute($lockedTimeline);
            $this->activity->record($lockedTimeline, 'timeline.resumed', $lockedTimeline->edition, $actor, [
                'active_item_id' => $lockedTimeline->active_item_id,
                'next_transition_at' => $lockedTimeline->next_transition_at?->toISOString(),
            ]);

            return $lockedTimeline->refresh();
        }, 3);
    }
}

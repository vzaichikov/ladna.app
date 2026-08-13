<?php

namespace App\Actions\Festivals;

use App\Models\FestivalTimeline;
use App\Models\FestivalTimelineItem;
use App\Support\Festivals\FestivalTimelineEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AdvanceFestivalTimeline
{
    public function __construct(
        private readonly FestivalActivityRecorder $activity,
        private readonly FestivalTimelineEngine $engine,
        private readonly ScheduleFestivalTimelineAdvance $scheduleAdvance,
    ) {}

    public function execute(
        int $timelineId,
        ?int $expectedActiveItemId,
        ?int $expectedLastFinishedItemId,
        int $expectedTransitionTimestamp,
    ): bool {
        return DB::transaction(function () use ($timelineId, $expectedActiveItemId, $expectedLastFinishedItemId, $expectedTransitionTimestamp): bool {
            $timeline = FestivalTimeline::query()->whereKey($timelineId)->lockForUpdate()->first();

            if (! $timeline
                || ! $timeline->started_at
                || $timeline->paused_at
                || $timeline->completed_at
                || $timeline->active_item_id !== $expectedActiveItemId
                || $timeline->last_finished_item_id !== $expectedLastFinishedItemId
                || $timeline->next_transition_at?->getTimestamp() !== $expectedTransitionTimestamp) {
                return false;
            }

            $items = FestivalTimelineItem::query()
                ->where('festival_timeline_id', $timeline->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $now = CarbonImmutable::now();

            if ($now->getTimestamp() < $expectedTransitionTimestamp) {
                $this->scheduleAdvance->execute($timeline);

                return false;
            }

            $before = [
                'active_item_id' => $timeline->active_item_id,
                'last_finished_item_id' => $timeline->last_finished_item_id,
                'next_transition_at' => $timeline->next_transition_at?->toISOString(),
            ];
            $changed = $this->engine->synchronize($timeline, $items, $now);

            if ($changed) {
                $this->activity->record($timeline, 'timeline.advanced', $timeline->edition, null, [
                    'before' => $before,
                    'after' => [
                        'active_item_id' => $timeline->active_item_id,
                        'last_finished_item_id' => $timeline->last_finished_item_id,
                        'next_transition_at' => $timeline->next_transition_at?->toISOString(),
                        'completed' => $timeline->completed_at !== null,
                    ],
                ]);
            }

            $this->scheduleAdvance->execute($timeline);

            return $changed;
        }, 3);
    }
}

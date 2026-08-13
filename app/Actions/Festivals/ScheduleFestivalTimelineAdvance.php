<?php

namespace App\Actions\Festivals;

use App\Jobs\AdvanceFestivalTimelineJob;
use App\Models\FestivalTimeline;

class ScheduleFestivalTimelineAdvance
{
    public function execute(FestivalTimeline $timeline): void
    {
        if (! $timeline->started_at || $timeline->paused_at || $timeline->completed_at || ! $timeline->next_transition_at) {
            return;
        }

        AdvanceFestivalTimelineJob::dispatch(
            $timeline->id,
            $timeline->active_item_id,
            $timeline->last_finished_item_id,
            $timeline->next_transition_at->getTimestamp(),
        )->delay($timeline->next_transition_at)->afterCommit();
    }
}

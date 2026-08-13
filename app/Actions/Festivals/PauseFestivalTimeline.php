<?php

namespace App\Actions\Festivals;

use App\Models\FestivalTimeline;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PauseFestivalTimeline
{
    public function __construct(private readonly FestivalActivityRecorder $activity) {}

    public function execute(FestivalTimeline $timeline, User $actor): FestivalTimeline
    {
        return DB::transaction(function () use ($timeline, $actor): FestivalTimeline {
            $lockedTimeline = FestivalTimeline::query()->whereKey($timeline->id)->lockForUpdate()->firstOrFail();

            if (! $lockedTimeline->started_at || $lockedTimeline->completed_at) {
                throw ValidationException::withMessages(['timeline' => __('app.festival_timeline_pause_invalid')]);
            }

            if (! $lockedTimeline->paused_at) {
                $lockedTimeline->forceFill(['paused_at' => now(), 'updated_by' => $actor->id])->save();
                $this->activity->record($lockedTimeline, 'timeline.paused', $lockedTimeline->edition, $actor);
            }

            return $lockedTimeline->refresh();
        }, 3);
    }
}

<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalEditionStatus;
use App\Models\FestivalEdition;
use App\Models\FestivalTimeline;
use App\Models\FestivalTimelineItem;
use App\Models\User;
use App\Support\Festivals\FestivalTimelineEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StartFestivalTimelines
{
    public function __construct(
        private readonly FestivalActivityRecorder $activity,
        private readonly FestivalTimelineEngine $engine,
        private readonly ScheduleFestivalTimelineAdvance $scheduleAdvance,
    ) {}

    /** @return list<FestivalTimeline> */
    public function execute(FestivalEdition $edition, User $actor): array
    {
        return DB::transaction(function () use ($edition, $actor): array {
            $lockedEdition = FestivalEdition::query()->whereKey($edition->id)->lockForUpdate()->firstOrFail();

            if ($lockedEdition->status !== FestivalEditionStatus::Published) {
                throw ValidationException::withMessages(['timeline' => __('app.festival_timeline_start_status_invalid')]);
            }

            $now = CarbonImmutable::now();
            $localToday = $now->setTimezone($lockedEdition->timezone)->toDateString();
            $localStartDate = $lockedEdition->starts_at->setTimezone($lockedEdition->timezone)->toDateString();
            $localEndDate = $lockedEdition->ends_at->setTimezone($lockedEdition->timezone)->toDateString();

            if ($localToday < $localStartDate || $localToday > $localEndDate) {
                throw ValidationException::withMessages(['timeline' => __('app.festival_timeline_start_date_invalid')]);
            }

            $timelines = FestivalTimeline::query()
                ->where('festival_edition_id', $lockedEdition->id)
                ->where('account_id', $lockedEdition->account_id)
                ->whereHas('stage', fn ($query) => $query->where('is_active', true))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($timelines->isEmpty()) {
                throw ValidationException::withMessages(['timeline' => __('app.festival_timeline_start_empty')]);
            }

            $items = FestivalTimelineItem::query()
                ->whereIn('festival_timeline_id', $timelines->pluck('id'))
                ->orderBy('festival_timeline_id')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->groupBy('festival_timeline_id');

            if (! $items->flatten()->contains('is_enabled', true)) {
                throw ValidationException::withMessages(['timeline' => __('app.festival_timeline_start_empty')]);
            }

            $lockedEdition->forceFill(['status' => FestivalEditionStatus::InProgress])->save();

            foreach ($timelines as $timeline) {
                $timeline->forceFill([
                    'started_at' => $now,
                    'paused_at' => null,
                    'completed_at' => null,
                    'updated_by' => $actor->id,
                ])->save();
                $this->engine->synchronize($timeline, $items->get($timeline->id, collect()), $now);
                $this->scheduleAdvance->execute($timeline);
            }

            $this->activity->record($lockedEdition, 'timeline.started', $lockedEdition, $actor, [
                'scenes' => $timelines->count(),
            ]);

            return $timelines->all();
        }, 3);
    }
}

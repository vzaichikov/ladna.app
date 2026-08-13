<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalEditionStatus;
use App\Models\FestivalEdition;
use App\Models\FestivalScheduleSlot;
use App\Models\FestivalStage;
use App\Models\FestivalTimeline;
use App\Models\User;
use App\Support\Festivals\FestivalProgramOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FillFestivalTimelines
{
    public function __construct(
        private readonly FestivalActivityRecorder $activity,
        private readonly FestivalProgramOrder $programOrder,
    ) {}

    /** @return list<FestivalTimeline> */
    public function execute(FestivalEdition $edition, User $actor): array
    {
        return DB::transaction(function () use ($edition, $actor): array {
            $lockedEdition = FestivalEdition::query()->whereKey($edition->id)->lockForUpdate()->firstOrFail();
            $existingTimelines = FestivalTimeline::query()
                ->where('festival_edition_id', $lockedEdition->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if (! in_array($lockedEdition->status, [FestivalEditionStatus::Draft, FestivalEditionStatus::Published], true)
                || $existingTimelines->contains(fn (FestivalTimeline $timeline): bool => $timeline->started_at !== null)) {
                throw ValidationException::withMessages(['timeline' => __('app.festival_timeline_fill_locked')]);
            }

            $stages = FestivalStage::query()
                ->where('festival_edition_id', $lockedEdition->id)
                ->where('account_id', $lockedEdition->account_id)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $slots = FestivalScheduleSlot::query()
                ->where('festival_edition_id', $lockedEdition->id)
                ->where('account_id', $lockedEdition->account_id)
                ->whereIn('festival_stage_id', $stages->pluck('id'))
                ->with(['entry:id,festival_edition_id,code,entry_name', 'category:id,festival_edition_id,name'])
                ->orderBy('festival_stage_id')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            FestivalTimeline::query()->where('festival_edition_id', $lockedEdition->id)->delete();
            $timelines = [];

            foreach ($stages as $stage) {
                $orderedSlots = $this->programOrder
                    ->ordered($slots->where('festival_stage_id', $stage->id)->values())
                    ->filter(fn (FestivalScheduleSlot $slot): bool => $slot->type->isTimed());

                if ($orderedSlots->isEmpty()) {
                    continue;
                }

                $timeline = FestivalTimeline::query()->create([
                    'account_id' => $lockedEdition->account_id,
                    'festival_edition_id' => $lockedEdition->id,
                    'festival_stage_id' => $stage->id,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);

                foreach ($orderedSlots->values() as $index => $slot) {
                    $durationSeconds = (int) max(1, $slot->starts_at->diffInSeconds($slot->ends_at));
                    $timeline->items()->create([
                        'account_id' => $lockedEdition->account_id,
                        'festival_edition_id' => $lockedEdition->id,
                        'festival_schedule_slot_id' => $slot->id,
                        'festival_entry_id' => $slot->festival_entry_id,
                        'entry_reference' => $slot->entry?->code,
                        'label' => $slot->displayName(),
                        'type' => $slot->type->value,
                        'notes' => $slot->notes,
                        'duration_seconds' => $durationSeconds,
                        'planned_starts_at' => $slot->starts_at,
                        'planned_ends_at' => $slot->ends_at,
                        'sort_order' => ($index + 1) * 10,
                        'is_enabled' => true,
                    ]);
                }

                $timelines[] = $timeline;
            }

            $this->activity->record($lockedEdition, 'timeline.filled', $lockedEdition, $actor, [
                'scenes' => count($timelines),
                'items' => collect($timelines)->sum(fn (FestivalTimeline $timeline): int => $timeline->items()->count()),
            ]);

            return $timelines;
        }, 3);
    }
}

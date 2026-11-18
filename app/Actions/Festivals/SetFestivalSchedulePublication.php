<?php

namespace App\Actions\Festivals;

use App\Models\FestivalEdition;
use App\Models\FestivalStage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetFestivalSchedulePublication
{
    public function __construct(private readonly FestivalActivityRecorder $activity) {}

    /**
     * @param  list<int|string>|null  $itemIds
     * @return array{changed: int, skipped: int}
     */
    public function execute(FestivalEdition $edition, FestivalStage $stage, bool $publish, ?array $itemIds, User $actor): array
    {
        return DB::transaction(function () use ($edition, $stage, $publish, $itemIds, $actor): array {
            $lockedStage = $edition->stages()
                ->where('account_id', $edition->account_id)
                ->whereKey($stage->id)
                ->lockForUpdate()
                ->firstOrFail();
            $slots = $lockedStage->slots()
                ->where('festival_edition_id', $edition->id)
                ->where('account_id', $edition->account_id)
                ->when($itemIds !== null, fn ($query) => $query->whereKey($itemIds))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($itemIds !== null && $slots->count() !== count($itemIds)) {
                throw ValidationException::withMessages(['item_ids' => __('app.festival_program_publication_invalid_selection')]);
            }

            $publishedAt = $publish ? now() : null;
            $changed = 0;
            $skipped = 0;

            foreach ($slots as $slot) {
                if ($publish && $slot->type->isTimed() && ! $slot->hasTimeRange()) {
                    $skipped++;

                    continue;
                }

                if (($slot->published_at !== null) === $publish) {
                    continue;
                }

                $before = $slot->published_at?->toIso8601String();
                $slot->update(['published_at' => $publishedAt, 'updated_by' => $actor->id]);
                $this->activity->record($slot, 'schedule.publication_changed', $edition, $actor, [
                    'before' => ['published_at' => $before],
                    'after' => ['published_at' => $slot->published_at?->toIso8601String()],
                ]);
                $changed++;
            }

            return compact('changed', 'skipped');
        }, 3);
    }
}

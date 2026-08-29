<?php

namespace App\Actions\Festivals;

use App\Models\FestivalEntry;
use App\Models\FestivalPenalty;
use App\Models\FestivalPortalUser;
use App\Models\FestivalResult;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveFestivalPenalty
{
    public function __construct(private readonly FestivalActivityRecorder $activity) {}

    /** @param array{points: mixed, reason: string} $input */
    public function execute(FestivalEntry $entry, ?FestivalPenalty $penalty, array $input, User|FestivalPortalUser $actor): FestivalPenalty
    {
        return DB::transaction(function () use ($entry, $penalty, $input, $actor): FestivalPenalty {
            $entry = FestivalEntry::query()->with('edition')->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $penalty = $penalty
                ? FestivalPenalty::query()->whereKey($penalty->id)->lockForUpdate()->firstOrFail()
                : new FestivalPenalty;
            abort_if($penalty->exists && $penalty->festival_entry_id !== $entry->id, 404);
            $penalty->fill([
                'account_id' => $entry->account_id,
                'festival_entry_id' => $entry->id,
                'festival_score_sheet_id' => null,
                'kind' => 'deduction',
                'points' => $input['points'],
                'reason' => $input['reason'],
                'created_by' => $penalty->created_by ?? ($actor instanceof User ? $actor->id : null),
            ])->save();
            $this->clearPublishedResults($entry);
            $this->activity->record($penalty, $penalty->wasRecentlyCreated ? 'penalty.created' : 'penalty.updated', $entry->edition, $actor, [
                'points' => $penalty->points,
                'reason' => $penalty->reason,
            ]);

            return $penalty->refresh();
        }, 3);
    }

    public function delete(FestivalPenalty $penalty, User|FestivalPortalUser $actor): void
    {
        DB::transaction(function () use ($penalty, $actor): void {
            $penalty = FestivalPenalty::query()->with('entry.edition')->whereKey($penalty->id)->lockForUpdate()->firstOrFail();
            $entry = $penalty->entry;
            $this->activity->record($penalty, 'penalty.deleted', $entry->edition, $actor, [
                'points' => $penalty->points,
                'reason' => $penalty->reason,
            ]);
            $penalty->delete();
            $this->clearPublishedResults($entry);
        }, 3);
    }

    private function clearPublishedResults(FestivalEntry $entry): void
    {
        FestivalResult::query()
            ->whereIn('festival_entry_id', FestivalEntry::query()
                ->select('id')
                ->where('festival_edition_id', $entry->festival_edition_id)
                ->where('festival_category_id', $entry->festival_category_id))
            ->delete();
    }
}

<?php

namespace App\Support\Festivals;

use App\Enums\FestivalEntryStatus;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FestivalParticipantLimits
{
    public function assertAvailable(FestivalEntry $entry, ?FestivalEditionPurchase $purchase): void
    {
        $participantIds = $entry->participants->modelKeys();
        $activeStatuses = [FestivalEntryStatus::Submitted->value, FestivalEntryStatus::UnderReview->value, FestivalEntryStatus::ChangesPending->value, FestivalEntryStatus::Accepted->value];

        if ($entry->edition->max_entries_per_participant !== null) {
            $counts = DB::table('festival_entry_participant')
                ->join('festival_entries', 'festival_entries.id', '=', 'festival_entry_participant.festival_entry_id')
                ->where('festival_entries.festival_edition_id', $entry->festival_edition_id)
                ->whereIn('festival_entries.status', $activeStatuses)
                ->whereIn('festival_entry_participant.festival_participant_id', $participantIds)
                ->where('festival_entries.id', '!=', $entry->id)
                ->lockForUpdate()
                ->selectRaw('festival_entry_participant.festival_participant_id, count(*) as aggregate')
                ->groupBy('festival_entry_participant.festival_participant_id')
                ->pluck('aggregate', 'festival_entry_participant.festival_participant_id');

            if (collect($participantIds)->contains(fn (int $id): bool => (int) ($counts[$id] ?? 0) >= $entry->edition->max_entries_per_participant)) {
                throw ValidationException::withMessages(['participants' => __('app.festival_entry_limit_exceeded', ['limit' => $entry->edition->max_entries_per_participant])]);
            }
        }

        if (! $purchase) {
            return;
        }

        $distinctIds = DB::table('festival_entry_participant')
            ->join('festival_entries', 'festival_entries.id', '=', 'festival_entry_participant.festival_entry_id')
            ->where('festival_entries.festival_edition_id', $entry->festival_edition_id)
            ->whereIn('festival_entries.status', $activeStatuses)
            ->where('festival_entries.id', '!=', $entry->id)
            ->lockForUpdate()
            ->distinct()
            ->pluck('festival_entry_participant.festival_participant_id')
            ->merge($participantIds)
            ->unique();

        if ($distinctIds->count() > $purchase->package->max_participants) {
            throw ValidationException::withMessages(['participants' => __('app.festival_participant_limit_exceeded', ['limit' => $purchase->package->max_participants])]);
        }
    }
}

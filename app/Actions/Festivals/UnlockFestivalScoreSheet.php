<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalScoreSheetStatus;
use App\Models\FestivalEntry;
use App\Models\FestivalResult;
use App\Models\FestivalScoreSheet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UnlockFestivalScoreSheet
{
    public function __construct(private readonly FestivalActivityRecorder $activity) {}

    public function execute(FestivalScoreSheet $scoreSheet, User $actor, string $reason): FestivalScoreSheet
    {
        return DB::transaction(function () use ($scoreSheet, $actor, $reason): FestivalScoreSheet {
            $scoreSheet = FestivalScoreSheet::query()
                ->with(['entry.edition'])
                ->whereKey($scoreSheet->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($scoreSheet->status !== FestivalScoreSheetStatus::Submitted) {
                throw ValidationException::withMessages(['reason' => __('app.festival_score_sheet_not_submitted')]);
            }

            $scoreSheet->forceFill([
                'status' => FestivalScoreSheetStatus::Draft,
                'submitted_at' => null,
            ])->save();
            FestivalResult::query()
                ->whereIn('festival_entry_id', FestivalEntry::query()
                    ->select('id')
                    ->where('festival_edition_id', $scoreSheet->entry->festival_edition_id)
                    ->where('festival_category_id', $scoreSheet->entry->festival_category_id))
                ->delete();
            $this->activity->record(
                $scoreSheet,
                'score_sheet.unlocked',
                $scoreSheet->entry->edition,
                $actor,
                ['reason' => trim($reason)],
            );

            return $scoreSheet->refresh();
        }, 3);
    }
}

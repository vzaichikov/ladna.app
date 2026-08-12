<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalBattleMatchStatus;
use App\Enums\FestivalCompetitionFormat;
use App\Models\FestivalBattleJudgeVote;
use App\Models\FestivalBattleMatch;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalPortalUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordFestivalBattleJudgeVote
{
    public function __construct(private readonly FestivalActivityRecorder $activity) {}

    public function execute(
        FestivalBattleMatch $match,
        FestivalJudgeAssignment $assignment,
        int $selectedEntryId,
        User|FestivalPortalUser $actor,
    ): FestivalBattleJudgeVote {
        return DB::transaction(function () use ($match, $assignment, $selectedEntryId, $actor): FestivalBattleJudgeVote {
            $match = FestivalBattleMatch::query()->with(['category', 'edition'])->whereKey($match->id)->lockForUpdate()->firstOrFail();
            $assignment = FestivalJudgeAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            abort_unless(
                $match->category->account_id === $match->account_id
                && $match->category->festival_edition_id === $match->festival_edition_id
                && $match->category->competition_format === FestivalCompetitionFormat::Knockout
                && $assignment->account_id === $match->account_id
                && $assignment->festival_edition_id === $match->festival_edition_id
                && $assignment->is_active
                && $assignment->categories()->whereKey($match->festival_category_id)->exists()
                && (($actor instanceof User && $assignment->user_id === $actor->id)
                    || ($actor instanceof FestivalPortalUser && $assignment->festival_portal_user_id === $actor->id)),
                403,
            );

            abort_unless(FestivalEntry::query()
                ->where('account_id', $match->account_id)
                ->where('festival_edition_id', $match->festival_edition_id)
                ->where('festival_category_id', $match->festival_category_id)
                ->whereKey([$match->entry_a_id, $match->entry_b_id])
                ->count() === 2, 404);

            if ($match->status !== FestivalBattleMatchStatus::Ready) {
                throw ValidationException::withMessages(['match' => __('app.festival_battle_match_not_open')]);
            }

            if (! in_array($selectedEntryId, [$match->entry_a_id, $match->entry_b_id], true)) {
                throw ValidationException::withMessages(['selected_entry_id' => __('app.festival_battle_entry_invalid')]);
            }

            $vote = FestivalBattleJudgeVote::query()->updateOrCreate(
                [
                    'festival_battle_match_id' => $match->id,
                    'festival_judge_assignment_id' => $assignment->id,
                ],
                [
                    'account_id' => $match->account_id,
                    'festival_edition_id' => $match->festival_edition_id,
                    'festival_category_id' => $match->festival_category_id,
                    'selected_entry_id' => $selectedEntryId,
                ],
            );
            $this->activity->record($match, 'battle.judge_vote_saved', $match->edition, $actor, [
                'festival_judge_assignment_id' => $assignment->id,
            ]);

            return $vote->refresh();
        }, 3);
    }
}

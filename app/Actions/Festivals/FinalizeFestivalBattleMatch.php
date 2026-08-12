<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalBattleMatchStatus;
use App\Enums\FestivalCompetitionFormat;
use App\Models\FestivalBattleJudgeVote;
use App\Models\FestivalBattleMatch;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinalizeFestivalBattleMatch
{
    public function __construct(private readonly FestivalActivityRecorder $activity) {}

    public function execute(
        FestivalBattleMatch $match,
        int $audienceVotesA,
        int $audienceVotesB,
        User $actor,
        ?int $tieWinnerEntryId = null,
        ?string $tieBreakReason = null,
    ): FestivalBattleMatch {
        return DB::transaction(function () use ($match, $audienceVotesA, $audienceVotesB, $actor, $tieWinnerEntryId, $tieBreakReason): FestivalBattleMatch {
            $match = FestivalBattleMatch::query()->with(['category', 'edition'])->whereKey($match->id)->lockForUpdate()->firstOrFail();
            abort_unless(
                $match->category->account_id === $match->account_id
                && $match->category->festival_edition_id === $match->festival_edition_id
                && $match->category->competition_format === FestivalCompetitionFormat::Knockout,
                404,
            );

            if ($match->status !== FestivalBattleMatchStatus::Ready || $match->entry_a_id === null || $match->entry_b_id === null) {
                throw ValidationException::withMessages(['match' => __('app.festival_battle_match_not_open')]);
            }

            abort_unless(FestivalEntry::query()
                ->where('account_id', $match->account_id)
                ->where('festival_edition_id', $match->festival_edition_id)
                ->where('festival_category_id', $match->festival_category_id)
                ->whereKey([$match->entry_a_id, $match->entry_b_id])
                ->count() === 2, 404);

            $audienceTotal = $audienceVotesA + $audienceVotesB;

            if ($audienceVotesA < 0 || $audienceVotesB < 0 || $audienceTotal < 1) {
                throw ValidationException::withMessages(['audience_votes_a' => __('app.festival_battle_audience_votes_required')]);
            }

            $assignmentIds = FestivalJudgeAssignment::query()
                ->where('festival_judge_assignments.account_id', $match->account_id)
                ->where('festival_judge_assignments.festival_edition_id', $match->festival_edition_id)
                ->where('festival_judge_assignments.is_active', true)
                ->whereHas('categories', fn ($query) => $query->whereKey($match->festival_category_id))
                ->lockForUpdate()
                ->pluck('festival_judge_assignments.id');

            if ($assignmentIds->count() !== 4) {
                throw ValidationException::withMessages(['match' => __('app.festival_battle_four_judges_required')]);
            }

            $votes = FestivalBattleJudgeVote::query()
                ->where('festival_battle_match_id', $match->id)
                ->whereIn('festival_judge_assignment_id', $assignmentIds)
                ->lockForUpdate()
                ->get();

            if ($votes->count() !== $assignmentIds->count()) {
                throw ValidationException::withMessages(['match' => __('app.festival_battle_judge_votes_incomplete')]);
            }

            if ($votes->contains(fn (FestivalBattleJudgeVote $vote): bool => ! in_array($vote->selected_entry_id, [$match->entry_a_id, $match->entry_b_id], true))) {
                throw ValidationException::withMessages(['match' => __('app.festival_battle_entry_invalid')]);
            }

            $judgeVotesA = $votes->where('selected_entry_id', $match->entry_a_id)->count();
            $judgeVotesB = $votes->where('selected_entry_id', $match->entry_b_id)->count();
            $judgeTotal = $judgeVotesA + $judgeVotesB;
            $weightedNumeratorA = ($judgeVotesA * $audienceTotal) + ($audienceVotesA * $judgeTotal);
            $weightedNumeratorB = ($judgeVotesB * $audienceTotal) + ($audienceVotesB * $judgeTotal);
            $isTie = $weightedNumeratorA === $weightedNumeratorB;

            if ($isTie) {
                if (! in_array($tieWinnerEntryId, [$match->entry_a_id, $match->entry_b_id], true)) {
                    throw ValidationException::withMessages(['tie_winner_entry_id' => __('app.festival_battle_tie_winner_required')]);
                }

                $tieReasonLength = mb_strlen(trim((string) $tieBreakReason));

                if ($tieReasonLength < 3 || $tieReasonLength > 2000) {
                    throw ValidationException::withMessages(['tie_break_reason' => __('app.festival_battle_tie_reason_required')]);
                }
            }

            $winnerEntryId = $isTie
                ? $tieWinnerEntryId
                : ($weightedNumeratorA > $weightedNumeratorB ? $match->entry_a_id : $match->entry_b_id);
            $combinedDenominator = $judgeTotal * $audienceTotal;
            $match->update([
                'audience_votes_a' => $audienceVotesA,
                'audience_votes_b' => $audienceVotesB,
                'judge_votes_a' => $judgeVotesA,
                'judge_votes_b' => $judgeVotesB,
                'jury_percentage_a' => round(($judgeVotesA / $judgeTotal) * 100, 4),
                'jury_percentage_b' => round(($judgeVotesB / $judgeTotal) * 100, 4),
                'audience_percentage_a' => round(($audienceVotesA / $audienceTotal) * 100, 4),
                'audience_percentage_b' => round(($audienceVotesB / $audienceTotal) * 100, 4),
                'combined_percentage_a' => round((50 * $weightedNumeratorA) / $combinedDenominator, 4),
                'combined_percentage_b' => round((50 * $weightedNumeratorB) / $combinedDenominator, 4),
                'winner_entry_id' => $winnerEntryId,
                'decided_by' => $actor->id,
                'tie_break_reason' => $isTie ? trim((string) $tieBreakReason) : null,
                'status' => FestivalBattleMatchStatus::Completed,
                'finalized_at' => now(),
            ]);
            $this->advanceWinner($match, (int) $winnerEntryId);
            $this->activity->record($match, 'battle.match_finalized', $match->edition, $actor, [
                'winner_entry_id' => $winnerEntryId,
                'combined_percentage_a' => $match->combined_percentage_a,
                'combined_percentage_b' => $match->combined_percentage_b,
                'tie_break_reason' => $match->tie_break_reason,
            ]);

            return $match->refresh()->load(['entryA', 'entryB', 'winner']);
        }, 3);
    }

    private function advanceWinner(FestivalBattleMatch $match, int $winnerEntryId): void
    {
        if ($match->next_match_id === null || $match->next_position === null) {
            return;
        }

        $nextMatch = FestivalBattleMatch::query()
            ->whereKey($match->next_match_id)
            ->where('account_id', $match->account_id)
            ->where('festival_edition_id', $match->festival_edition_id)
            ->where('festival_category_id', $match->festival_category_id)
            ->lockForUpdate()
            ->firstOrFail();
        $column = $match->next_position === 'a' ? 'entry_a_id' : 'entry_b_id';
        $existingEntryId = $nextMatch->getAttribute($column);

        if ($existingEntryId !== null && (int) $existingEntryId !== $winnerEntryId) {
            throw ValidationException::withMessages(['match' => __('app.festival_battle_advancement_conflict')]);
        }

        $nextMatch->setAttribute($column, $winnerEntryId);

        if ($nextMatch->entry_a_id !== null && $nextMatch->entry_b_id !== null) {
            $nextMatch->status = FestivalBattleMatchStatus::Ready;
        }

        $nextMatch->save();
    }
}

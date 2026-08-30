<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalBattleMatchStatus;
use App\Enums\FestivalCompetitionFormat;
use App\Models\AccountApiToken;
use App\Models\FestivalBattleJudgeVote;
use App\Models\FestivalBattleMatch;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitFestivalBattleAudienceScore
{
    public function __construct(
        private readonly FestivalActivityRecorder $activity,
        private readonly FinalizeFestivalBattleMatch $finalize,
    ) {}

    /**
     * @param  array<string, mixed>  $measurement
     * @return array{match: FestivalBattleMatch, conflict: bool}
     */
    public function execute(
        FestivalBattleMatch $match,
        int $audienceScoreA,
        int $audienceScoreB,
        AccountApiToken $actor,
        array $measurement = [],
    ): array {
        $outcome = DB::transaction(function () use ($match, $audienceScoreA, $audienceScoreB, $actor, $measurement): array {
            $lockedMatch = FestivalBattleMatch::query()
                ->with(['category', 'edition'])
                ->whereKey($match->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertMatch($lockedMatch, $actor);

            if ($lockedMatch->status === FestivalBattleMatchStatus::Completed) {
                return [
                    'match' => $lockedMatch,
                    'conflict' => ! $this->scoresMatch($lockedMatch, $audienceScoreA, $audienceScoreB),
                    'should_finalize' => false,
                ];
            }

            if ($lockedMatch->status !== FestivalBattleMatchStatus::Ready
                || $lockedMatch->entry_a_id === null
                || $lockedMatch->entry_b_id === null) {
                throw ValidationException::withMessages(['match' => __('app.festival_battle_match_not_open')]);
            }

            $hasStoredScore = $lockedMatch->audience_votes_a !== null || $lockedMatch->audience_votes_b !== null;

            if ($hasStoredScore && ! $this->scoresMatch($lockedMatch, $audienceScoreA, $audienceScoreB)) {
                return ['match' => $lockedMatch, 'conflict' => true, 'should_finalize' => false];
            }

            if (! $hasStoredScore) {
                $lockedMatch->update([
                    'audience_votes_a' => $audienceScoreA,
                    'audience_votes_b' => $audienceScoreB,
                ]);
                $this->activity->record($lockedMatch, 'battle.audience_score_recorded', $lockedMatch->edition, $actor, [
                    'audience_score_a' => $audienceScoreA,
                    'audience_score_b' => $audienceScoreB,
                    'measurement' => Arr::only((array) ($measurement['measurement'] ?? []), [
                        'metric',
                        'baseline_duration_ms',
                        'duration_ms',
                        'baseline_dbfs',
                        'mean_dbfs_a',
                        'mean_dbfs_b',
                        'peak_dbfs_a',
                        'peak_dbfs_b',
                    ]),
                ]);
            }

            return [
                'match' => $lockedMatch,
                'conflict' => false,
                'should_finalize' => $this->canFinalizeWithoutTie($lockedMatch),
            ];
        }, 3);

        if (! $outcome['should_finalize']) {
            return ['match' => $outcome['match']->refresh(), 'conflict' => $outcome['conflict']];
        }

        try {
            $finalizedMatch = $this->finalize->execute(
                $outcome['match'],
                $audienceScoreA,
                $audienceScoreB,
                $actor,
            );
        } catch (ValidationException $exception) {
            $currentMatch = FestivalBattleMatch::query()->findOrFail($match->id);

            if ($currentMatch->status !== FestivalBattleMatchStatus::Completed
                || ! $this->scoresMatch($currentMatch, $audienceScoreA, $audienceScoreB)) {
                throw $exception;
            }

            $finalizedMatch = $currentMatch;
        }

        return ['match' => $finalizedMatch, 'conflict' => false];
    }

    private function assertMatch(FestivalBattleMatch $match, AccountApiToken $actor): void
    {
        abort_unless(
            $actor->account_id === $match->account_id
            && $match->category?->account_id === $match->account_id
            && $match->category?->festival_edition_id === $match->festival_edition_id
            && $match->category?->competition_format === FestivalCompetitionFormat::Knockout
            && FestivalEntry::query()
                ->where('account_id', $match->account_id)
                ->where('festival_edition_id', $match->festival_edition_id)
                ->where('festival_category_id', $match->festival_category_id)
                ->whereKey([$match->entry_a_id, $match->entry_b_id])
                ->count() === 2,
            404,
        );
    }

    private function scoresMatch(FestivalBattleMatch $match, int $scoreA, int $scoreB): bool
    {
        return $match->audience_votes_a === $scoreA && $match->audience_votes_b === $scoreB;
    }

    private function canFinalizeWithoutTie(FestivalBattleMatch $match): bool
    {
        $assignmentIds = FestivalJudgeAssignment::query()
            ->where('festival_judge_assignments.account_id', $match->account_id)
            ->where('festival_judge_assignments.festival_edition_id', $match->festival_edition_id)
            ->where('festival_judge_assignments.is_active', true)
            ->whereHas('categories', fn ($query) => $query->whereKey($match->festival_category_id))
            ->pluck('festival_judge_assignments.id');

        if ($assignmentIds->count() !== 4) {
            return false;
        }

        $votes = FestivalBattleJudgeVote::query()
            ->where('festival_battle_match_id', $match->id)
            ->whereIn('festival_judge_assignment_id', $assignmentIds)
            ->get(['selected_entry_id']);

        if ($votes->count() !== 4
            || $votes->contains(fn (FestivalBattleJudgeVote $vote): bool => ! in_array($vote->selected_entry_id, [$match->entry_a_id, $match->entry_b_id], true))) {
            return false;
        }

        $audienceTotal = (int) $match->audience_votes_a + (int) $match->audience_votes_b;
        $votesA = $votes->where('selected_entry_id', $match->entry_a_id)->count();
        $votesB = $votes->where('selected_entry_id', $match->entry_b_id)->count();

        return (($votesA * $audienceTotal) + ((int) $match->audience_votes_a * 4))
            !== (($votesB * $audienceTotal) + ((int) $match->audience_votes_b * 4));
    }
}

<?php

namespace App\Support\Festivals;

use App\Enums\FestivalBattleMatchStatus;
use App\Models\FestivalBattleJudgeVote;
use App\Models\FestivalBattleMatch;
use App\Models\FestivalJudgeAssignment;

class FestivalBattleMatchSnapshot
{
    /** @return array<string, mixed> */
    public function for(FestivalBattleMatch $match): array
    {
        $match->loadMissing([
            'edition:id,title,status',
            'category:id,name',
            'entryA:id,entry_name',
            'entryB:id,entry_name',
            'winner:id,entry_name',
        ]);

        $assignmentIds = FestivalJudgeAssignment::query()
            ->where('festival_judge_assignments.account_id', $match->account_id)
            ->where('festival_judge_assignments.festival_edition_id', $match->festival_edition_id)
            ->where('festival_judge_assignments.is_active', true)
            ->whereHas('categories', fn ($query) => $query->whereKey($match->festival_category_id))
            ->pluck('festival_judge_assignments.id');
        $votes = FestivalBattleJudgeVote::query()
            ->where('festival_battle_match_id', $match->id)
            ->whereIn('festival_judge_assignment_id', $assignmentIds)
            ->get(['festival_judge_assignment_id', 'selected_entry_id']);
        $votesA = $match->status === FestivalBattleMatchStatus::Completed && $match->judge_votes_a !== null
            ? $match->judge_votes_a
            : $votes->where('selected_entry_id', $match->entry_a_id)->count();
        $votesB = $match->status === FestivalBattleMatchStatus::Completed && $match->judge_votes_b !== null
            ? $match->judge_votes_b
            : $votes->where('selected_entry_id', $match->entry_b_id)->count();
        $validVoteCount = $votesA + $votesB;
        $audienceScoreA = $match->audience_votes_a;
        $audienceScoreB = $match->audience_votes_b;
        $audienceTotal = $audienceScoreA !== null && $audienceScoreB !== null
            ? $audienceScoreA + $audienceScoreB
            : 0;
        $isJudgeVoteComplete = $assignmentIds->count() === 4 && $validVoteCount === 4;
        $isTie = $isJudgeVoteComplete
            && $audienceTotal > 0
            && (($votesA * $audienceTotal) + ($audienceScoreA * 4)) === (($votesB * $audienceTotal) + ($audienceScoreB * 4));

        return [
            'id' => $match->id,
            'edition_label' => $match->edition->title,
            'category_label' => $match->category->name,
            'round_number' => $match->round,
            'position' => $match->position,
            'state' => $this->state($match, $audienceTotal, $isJudgeVoteComplete, $isTie),
            'performer_a' => $this->performer($match->entryA),
            'performer_b' => $this->performer($match->entryB),
            'judge_votes' => [
                'required' => 4,
                'submitted' => $validVoteCount,
                'a' => $votesA,
                'b' => $votesB,
            ],
            'audience' => [
                'score_a' => $audienceScoreA,
                'score_b' => $audienceScoreB,
                'percentage_a' => $this->percentage($audienceScoreA, $audienceTotal, $match->audience_percentage_a),
                'percentage_b' => $this->percentage($audienceScoreB, $audienceTotal, $match->audience_percentage_b),
            ],
            'combined' => [
                'percentage_a' => $match->combined_percentage_a !== null ? (float) $match->combined_percentage_a : null,
                'percentage_b' => $match->combined_percentage_b !== null ? (float) $match->combined_percentage_b : null,
            ],
            'winner' => $this->performer($match->winner),
        ];
    }

    private function state(FestivalBattleMatch $match, int $audienceTotal, bool $isJudgeVoteComplete, bool $isTie): string
    {
        if ($match->status === FestivalBattleMatchStatus::Completed) {
            return 'completed';
        }

        if ($audienceTotal === 0) {
            return 'ready';
        }

        if (! $isJudgeVoteComplete) {
            return 'waiting_for_judges';
        }

        return $isTie ? 'jury_decision_required' : 'ready';
    }

    /** @return array{id: int, name: string}|null */
    private function performer(mixed $entry): ?array
    {
        if ($entry === null) {
            return null;
        }

        return [
            'id' => (int) $entry->id,
            'name' => (string) $entry->entry_name,
        ];
    }

    private function percentage(?int $score, int $total, mixed $storedPercentage): ?float
    {
        if ($storedPercentage !== null) {
            return (float) $storedPercentage;
        }

        return $score !== null && $total > 0 ? round(($score / $total) * 100, 4) : null;
    }
}

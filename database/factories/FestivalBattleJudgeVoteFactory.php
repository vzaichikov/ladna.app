<?php

namespace Database\Factories;

use App\Models\FestivalBattleJudgeVote;
use App\Models\FestivalBattleMatch;
use App\Models\FestivalJudgeAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FestivalBattleJudgeVote>
 */
class FestivalBattleJudgeVoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'festival_battle_match_id' => FestivalBattleMatch::factory(),
            'account_id' => fn (array $attributes): int => FestivalBattleMatch::query()->findOrFail($attributes['festival_battle_match_id'])->account_id,
            'festival_edition_id' => fn (array $attributes): int => FestivalBattleMatch::query()->findOrFail($attributes['festival_battle_match_id'])->festival_edition_id,
            'festival_category_id' => fn (array $attributes): int => FestivalBattleMatch::query()->findOrFail($attributes['festival_battle_match_id'])->festival_category_id,
            'festival_judge_assignment_id' => function (array $attributes): int {
                $match = FestivalBattleMatch::query()->findOrFail($attributes['festival_battle_match_id']);
                $assignment = FestivalJudgeAssignment::factory()->for($match->edition)->create(['account_id' => $match->account_id]);
                $assignment->categories()->attach($match->festival_category_id, ['account_id' => $match->account_id]);

                return $assignment->id;
            },
            'selected_entry_id' => fn (array $attributes): int => FestivalBattleMatch::query()->findOrFail($attributes['festival_battle_match_id'])->entry_a_id,
        ];
    }
}

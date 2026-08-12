<?php

namespace Database\Factories;

use App\Models\FestivalBattleMatch;
use App\Models\FestivalCategory;
use App\Models\FestivalEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FestivalBattleMatch>
 */
class FestivalBattleMatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'festival_category_id' => FestivalCategory::factory(),
            'account_id' => fn (array $attributes): int => FestivalCategory::query()->findOrFail($attributes['festival_category_id'])->account_id,
            'festival_edition_id' => fn (array $attributes): int => FestivalCategory::query()->findOrFail($attributes['festival_category_id'])->festival_edition_id,
            'round' => 1,
            'position' => fake()->unique()->numberBetween(1, 100000),
            'entry_a_id' => fn (array $attributes): int => FestivalEntry::factory()->for(FestivalCategory::query()->findOrFail($attributes['festival_category_id']))->create()->id,
            'entry_b_id' => fn (array $attributes): int => FestivalEntry::factory()->for(FestivalCategory::query()->findOrFail($attributes['festival_category_id']))->create()->id,
            'status' => 'ready',
        ];
    }
}

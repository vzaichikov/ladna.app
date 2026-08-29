<?php

namespace Database\Factories;

use App\Models\FestivalEdition;
use App\Models\FestivalNomination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FestivalNomination>
 */
class FestivalNominationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => fn (array $attributes) => FestivalEdition::findOrFail($attributes['festival_edition_id'])->account_id,
            'festival_edition_id' => FestivalEdition::factory(),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->optional()->paragraph(),
            'presented_by' => fake()->optional()->company(),
            'prize' => fake()->optional()->sentence(),
            'is_active' => true,
            'show_in_mini_app' => false,
            'sort_order' => 0,
        ];
    }
}

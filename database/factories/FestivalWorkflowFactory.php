<?php

namespace Database\Factories;

use App\Models\FestivalEdition;
use App\Models\FestivalWorkflow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FestivalWorkflow>
 */
class FestivalWorkflowFactory extends Factory
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
            'name' => 'Registration '.fake()->unique()->word(),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}

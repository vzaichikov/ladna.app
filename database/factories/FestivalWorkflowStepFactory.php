<?php

namespace Database\Factories;

use App\Models\FestivalWorkflow;
use App\Models\FestivalWorkflowStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FestivalWorkflowStep>
 */
class FestivalWorkflowStepFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => fn (array $attributes) => FestivalWorkflow::findOrFail($attributes['festival_workflow_id'])->account_id,
            'festival_workflow_id' => FestivalWorkflow::factory(),
            'code' => fake()->unique()->slug(2),
            'type' => 'form',
            'title' => fake()->sentence(3),
            'sort_order' => 10,
            'review_mode' => 'automatic',
            'review_effect' => 'none',
            'is_active' => true,
        ];
    }
}

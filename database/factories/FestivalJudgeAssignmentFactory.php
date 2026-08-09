<?php

namespace Database\Factories;

use App\Models\FestivalEdition;
use App\Models\FestivalJudgeAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FestivalJudgeAssignment> */
class FestivalJudgeAssignmentFactory extends Factory
{
    protected $model = FestivalJudgeAssignment::class;

    public function definition(): array
    {
        return ['account_id' => fn (array $attributes) => FestivalEdition::findOrFail($attributes['festival_edition_id'])->account_id, 'festival_edition_id' => FestivalEdition::factory(), 'user_id' => User::factory(), 'display_name' => fake()->name(), 'is_active' => true];
    }
}

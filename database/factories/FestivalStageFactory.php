<?php

namespace Database\Factories;

use App\Models\FestivalEdition;
use App\Models\FestivalStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FestivalStage> */
class FestivalStageFactory extends Factory
{
    protected $model = FestivalStage::class;

    public function definition(): array
    {
        return ['account_id' => fn (array $attributes) => FestivalEdition::findOrFail($attributes['festival_edition_id'])->account_id, 'festival_edition_id' => FestivalEdition::factory(), 'name' => fake()->unique()->word().' stage', 'is_active' => true];
    }
}

<?php

namespace Database\Factories;

use App\Models\FestivalEdition;
use App\Models\FestivalRubric;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FestivalRubric> */
class FestivalRubricFactory extends Factory
{
    protected $model = FestivalRubric::class;

    public function definition(): array
    {
        return ['account_id' => fn (array $attributes) => FestivalEdition::findOrFail($attributes['festival_edition_id'])->account_id, 'festival_edition_id' => FestivalEdition::factory(), 'name' => 'Main rubric', 'version' => 1, 'is_active' => true];
    }
}

<?php

namespace Database\Factories;

use App\Models\FestivalEdition;
use App\Models\FestivalRequirementDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FestivalRequirementDefinition> */
class FestivalRequirementDefinitionFactory extends Factory
{
    protected $model = FestivalRequirementDefinition::class;

    public function definition(): array
    {
        return ['account_id' => fn (array $attributes) => FestivalEdition::findOrFail($attributes['festival_edition_id'])->account_id, 'festival_edition_id' => FestivalEdition::factory(), 'type' => 'music', 'name' => 'Performance music', 'stage' => 'final', 'allowed_extensions' => ['mp3'], 'allowed_mime_types' => ['audio/mpeg'], 'max_size_kb' => 20480, 'is_required' => true];
    }
}

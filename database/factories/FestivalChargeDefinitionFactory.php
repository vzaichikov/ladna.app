<?php

namespace Database\Factories;

use App\Models\FestivalChargeDefinition;
use App\Models\FestivalEdition;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FestivalChargeDefinition> */
class FestivalChargeDefinitionFactory extends Factory
{
    protected $model = FestivalChargeDefinition::class;

    public function definition(): array
    {
        return ['account_id' => fn (array $attributes) => FestivalEdition::findOrFail($attributes['festival_edition_id'])->account_id, 'festival_edition_id' => FestivalEdition::factory(), 'kind' => 'participation', 'name' => 'Participation fee', 'amount_cents' => 100000, 'currency' => 'UAH', 'is_active' => true];
    }
}

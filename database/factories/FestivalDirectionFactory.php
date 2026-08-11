<?php

namespace Database\Factories;

use App\Models\FestivalDirection;
use App\Models\FestivalEdition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FestivalDirection> */
class FestivalDirectionFactory extends Factory
{
    protected $model = FestivalDirection::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'account_id' => fn (array $attributes) => FestivalEdition::findOrFail($attributes['festival_edition_id'])->account_id,
            'festival_edition_id' => FestivalEdition::factory(),
            'code' => Str::slug($name),
            'name' => Str::title($name),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}

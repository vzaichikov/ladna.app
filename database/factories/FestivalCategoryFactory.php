<?php

namespace Database\Factories;

use App\Models\FestivalCategory;
use App\Models\FestivalDirection;
use App\Models\FestivalEdition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FestivalCategory> */
class FestivalCategoryFactory extends Factory
{
    protected $model = FestivalCategory::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'account_id' => fn (array $attributes) => FestivalEdition::findOrFail($attributes['festival_edition_id'])->account_id,
            'festival_edition_id' => FestivalEdition::factory(),
            'festival_direction_id' => fn (array $attributes) => FestivalDirection::factory()->create([
                'account_id' => FestivalEdition::findOrFail($attributes['festival_edition_id'])->account_id,
                'festival_edition_id' => $attributes['festival_edition_id'],
            ])->id,
            'code' => Str::slug($name),
            'name' => Str::title($name),
            'min_members' => 1,
            'max_members' => 1,
            'competition_format' => 'scored',
            'minimum_entries_to_run' => 1,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}

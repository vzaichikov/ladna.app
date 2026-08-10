<?php

namespace Database\Factories;

use App\Models\FestivalCategory;
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

        return ['account_id' => fn (array $attributes) => FestivalEdition::findOrFail($attributes['festival_edition_id'])->account_id, 'festival_edition_id' => FestivalEdition::factory(), 'code' => Str::slug($name), 'name' => Str::title($name), 'workflow' => 'review', 'min_members' => 1, 'max_members' => 1, 'is_active' => true, 'sort_order' => 0];
    }
}

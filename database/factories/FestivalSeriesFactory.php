<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\FestivalSeries;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FestivalSeries> */
class FestivalSeriesFactory extends Factory
{
    protected $model = FestivalSeries::class;

    public function definition(): array
    {
        $name = fake()->unique()->company().' Festival';

        return ['account_id' => Account::factory(), 'name' => $name, 'slug' => Str::slug($name), 'summary' => fake()->sentence(), 'organizer_name' => fake()->name(), 'organizer_email' => fake()->safeEmail(), 'brand_color' => '#7c3aed', 'is_active' => true];
    }
}

<?php

namespace Database\Factories;

use App\Models\FestivalEdition;
use App\Models\FestivalSeries;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FestivalEdition> */
class FestivalEditionFactory extends Factory
{
    protected $model = FestivalEdition::class;

    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);
        $startsAt = now()->addMonth();

        return ['account_id' => fn (array $attributes) => FestivalSeries::findOrFail($attributes['festival_series_id'])->account_id, 'festival_series_id' => FestivalSeries::factory(), 'slug' => Str::slug($title), 'title' => $title, 'status' => 'draft', 'registration_status' => 'closed', 'summary' => fake()->sentence(), 'venue_name' => fake()->company(), 'venue_address' => fake()->address(), 'timezone' => 'Europe/Kyiv', 'currency' => 'UAH', 'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addDay(), 'age_reference_date' => $startsAt->toDateString()];
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['status' => 'published', 'registration_status' => 'open', 'published_at' => now(), 'registration_opens_at' => now()->subDay(), 'registration_closes_at' => now()->addWeeks(2)]);
    }
}

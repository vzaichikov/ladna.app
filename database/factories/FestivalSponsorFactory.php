<?php

namespace Database\Factories;

use App\Models\FestivalEdition;
use App\Models\FestivalSeries;
use App\Models\FestivalSponsor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FestivalSponsor>
 */
class FestivalSponsorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'festival_edition_id' => function (): int {
                $series = FestivalSeries::factory()->create();

                return FestivalEdition::factory()->for($series)->create(['account_id' => $series->account_id])->id;
            },
            'account_id' => fn (array $attributes): int => FestivalEdition::findOrFail($attributes['festival_edition_id'])->account_id,
            'name' => fake()->company(),
            'url' => fake()->url(),
            'logo_path' => 'festivals/sponsors/'.fake()->uuid().'.png',
            'short_description' => fake()->sentence(),
            'giveaway_description' => fake()->paragraph(),
            'show_on_all_pages' => false,
        ];
    }

    public function onAllPages(): static
    {
        return $this->state(fn (): array => ['show_on_all_pages' => true]);
    }
}

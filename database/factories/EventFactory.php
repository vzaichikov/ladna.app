<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'slug' => Str::slug(fake()->unique()->sentence(3)),
            'status' => 'draft',
            'title' => fake()->sentence(3),
            'summary' => fake()->sentence(),
            'venue_kind' => 'external',
            'external_venue_name' => fake()->company(),
            'external_address' => fake()->address(),
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(2),
            'timezone' => 'Europe/Kyiv',
            'currency' => 'UAH',
            'capacity' => 100,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['status' => 'published', 'published_at' => now()]);
    }
}

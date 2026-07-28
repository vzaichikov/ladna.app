<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Event;
use App\Models\EventMedia;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventMedia>
 */
class EventMediaFactory extends Factory
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
            'event_id' => Event::factory(),
            'kind' => 'video',
            'external_url' => 'https://youtu.be/dQw4w9WgXcQ',
            'alt_text' => fake()->sentence(3),
            'sort_order' => 10,
            'is_cover' => false,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Event;
use App\Models\EventTicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventTicketType>
 */
class EventTicketTypeFactory extends Factory
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
            'name' => fake()->words(2, true),
            'inventory' => 50,
            'price_cents' => 50000,
            'max_per_order' => 10,
            'is_active' => true,
            'sort_order' => 10,
        ];
    }
}

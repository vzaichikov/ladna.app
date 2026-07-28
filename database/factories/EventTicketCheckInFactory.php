<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\EventTicketCheckIn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventTicketCheckIn>
 */
class EventTicketCheckInFactory extends Factory
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
            'event_ticket_id' => EventTicket::factory(),
            'action' => 'check_in',
            'source' => 'door_list',
            'actor_name' => fake()->name(),
            'actor_email' => fake()->safeEmail(),
            'occurred_at' => now(),
        ];
    }
}

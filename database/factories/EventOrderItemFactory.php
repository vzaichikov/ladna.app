<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Event;
use App\Models\EventOrder;
use App\Models\EventOrderItem;
use App\Models\EventTicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventOrderItem>
 */
class EventOrderItemFactory extends Factory
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
            'event_order_id' => EventOrder::factory(),
            'event_ticket_type_id' => EventTicketType::factory(),
            'ticket_type_name' => 'General admission',
            'price_tier' => 'regular',
            'unit_price_cents' => 50000,
            'quantity' => 1,
            'total_cents' => 50000,
        ];
    }
}

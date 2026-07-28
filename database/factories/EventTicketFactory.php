<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Event;
use App\Models\EventOrder;
use App\Models\EventOrderItem;
use App\Models\EventTicket;
use App\Models\EventTicketType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EventTicket>
 */
class EventTicketFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = Str::random(64);

        return [
            'account_id' => Account::factory(),
            'event_id' => Event::factory(),
            'event_order_id' => EventOrder::factory(),
            'event_order_item_id' => EventOrderItem::factory(),
            'event_ticket_type_id' => EventTicketType::factory(),
            'code' => 'EVT-'.Str::upper(Str::random(4)).'-'.Str::upper(Str::random(4)),
            'token_encrypted' => $token,
            'token_hash' => hash('sha256', $token),
            'status' => 'valid',
        ];
    }
}

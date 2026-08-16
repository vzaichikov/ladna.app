<?php

namespace Database\Factories;

use App\Models\EventCashEntry;
use App\Models\EventOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EventCashEntry>
 */
class EventCashEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => fn (array $attributes): int => EventOrder::findOrFail($attributes['event_order_id'])->account_id,
            'event_id' => fn (array $attributes): int => EventOrder::findOrFail($attributes['event_order_id'])->event_id,
            'event_order_id' => EventOrder::factory(),
            'source_key' => 'event-cash:'.Str::uuid(),
            'direction' => EventCashEntry::DirectionIn,
            'purpose' => EventCashEntry::PurposeEntranceTicketSale,
            'amount_cents' => fake()->numberBetween(1000, 100000),
            'currency' => 'UAH',
            'actor_name' => fake()->name(),
            'reason' => 'Entrance ticket sale.',
            'occurred_at' => now(),
        ];
    }
}

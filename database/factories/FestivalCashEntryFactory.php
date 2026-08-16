<?php

namespace Database\Factories;

use App\Models\FestivalCashEntry;
use App\Models\FestivalTicketOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FestivalCashEntry>
 */
class FestivalCashEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => fn (array $attributes): int => FestivalTicketOrder::findOrFail($attributes['festival_ticket_order_id'])->account_id,
            'festival_edition_id' => fn (array $attributes): int => FestivalTicketOrder::findOrFail($attributes['festival_ticket_order_id'])->festival_edition_id,
            'festival_ticket_order_id' => FestivalTicketOrder::factory(),
            'source_key' => 'festival-cash:'.Str::uuid(),
            'direction' => FestivalCashEntry::DirectionIn,
            'purpose' => FestivalCashEntry::PurposeEntranceTicketSale,
            'amount_cents' => fake()->numberBetween(1000, 100000),
            'currency' => 'UAH',
            'actor_name' => fake()->name(),
            'reason' => 'Entrance ticket sale.',
            'occurred_at' => now(),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Location;
use App\Models\StudioCashEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudioCashEntry>
 */
class StudioCashEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $account = Account::factory();

        return [
            'account_id' => $account,
            'location_id' => Location::factory()->for($account),
            'direction' => StudioCashEntry::DirectionIn,
            'amount_cents' => fake()->numberBetween(1000, 100000),
            'currency' => 'UAH',
            'occurred_at' => now(),
            'actor_name' => fake()->name(),
            'actor_email' => fake()->safeEmail(),
            'actor_role' => 'owner',
            'reason' => fake()->sentence(),
        ];
    }
}

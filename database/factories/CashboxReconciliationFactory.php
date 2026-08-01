<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\CashboxReconciliation;
use App\Models\FinanceEpoch;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CashboxReconciliation>
 */
class CashboxReconciliationFactory extends Factory
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
            'finance_epoch_id' => FinanceEpoch::factory()->for($account),
            'location_id' => Location::factory()->for($account),
            'kind' => CashboxReconciliation::KindCount,
            'currency' => 'UAH',
            'expected_before_cents' => 10000,
            'actual_counted_cents' => 9900,
            'variance_cents' => -100,
            'idempotency_key' => (string) Str::uuid(),
            'occurred_at' => now(),
            'actor_name' => fake()->name(),
            'actor_role' => 'owner',
            'reason' => fake()->sentence(),
        ];
    }
}

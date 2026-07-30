<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseRefund;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CustomerPurchaseRefund>
 */
class CustomerPurchaseRefundFactory extends Factory
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
            'customer_purchase_id' => CustomerPurchase::factory()->for($account),
            'location_id' => null,
            'cash_location_id' => null,
            'method' => CustomerPurchaseRefund::MethodCashless,
            'amount_cents' => fake()->numberBetween(1000, 50000),
            'currency' => 'UAH',
            'refunded_at' => now(),
            'idempotency_key' => (string) Str::uuid(),
            'actor_name' => fake()->name(),
            'actor_email' => fake()->safeEmail(),
            'actor_role' => 'owner',
            'reason' => fake()->sentence(),
        ];
    }
}

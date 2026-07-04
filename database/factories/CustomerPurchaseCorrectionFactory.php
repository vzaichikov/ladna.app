<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseCorrection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerPurchaseCorrection>
 */
class CustomerPurchaseCorrectionFactory extends Factory
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
            'previous_amount_cents' => 10000,
            'new_amount_cents' => 12000,
            'previous_paid_at' => now()->subDay(),
            'new_paid_at' => now(),
            'actor_name' => fake()->name(),
            'actor_email' => fake()->safeEmail(),
            'actor_role' => 'owner',
            'reason' => fake()->sentence(),
        ];
    }
}

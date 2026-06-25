<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerClassPass>
 */
class CustomerClassPassFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = strtoupper(fake()->bothify('????-####'));
        $purchasedAt = now();
        $totalValidityDays = 180;

        return [
            'account_id' => Account::factory(),
            'customer_id' => Customer::factory(),
            'class_pass_plan_id' => ClassPassPlan::factory(),
            'code' => $code,
            'source' => 'manual',
            'status' => 'active',
            'plan_name' => fake()->randomElement(['START', 'BASE', 'Private 1h']),
            'plan_slug' => fake()->slug(),
            'price_cents' => fake()->numberBetween(50000, 500000),
            'currency' => 'UAH',
            'sessions_count' => fake()->randomElement([1, 4, 8, 12]),
            'validity_days' => 30,
            'total_validity_days' => $totalValidityDays,
            'reserved_sessions_count' => 0,
            'used_sessions_count' => 0,
            'purchased_at' => $purchasedAt,
            'opened_at' => null,
            'expires_at' => null,
            'usable_until_at' => $purchasedAt->copy()->addDays($totalValidityDays),
            'closed_at' => null,
            'is_active' => true,
        ];
    }
}

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
        $account = Account::factory();

        return [
            'account_id' => $account,
            'customer_id' => Customer::factory()->for($account),
            'class_pass_plan_id' => ClassPassPlan::factory()->for($account),
            'code' => $code,
            'source' => 'manual',
            'issued_location_id' => null,
            'is_paid' => false,
            'issued_by_actor_user_id' => null,
            'issued_by_actor_trainer_id' => null,
            'issued_by_actor_name' => null,
            'issued_by_actor_email' => null,
            'issued_by_actor_role' => null,
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

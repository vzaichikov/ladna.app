<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CustomerPurchase>
 */
class CustomerPurchaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $account = Account::factory();
        $planName = fake()->randomElement(['START', 'BASE', 'PRO']);

        return [
            'account_id' => $account,
            'customer_id' => Customer::factory()->for($account),
            'location_id' => null,
            'class_pass_plan_id' => ClassPassPlan::factory()->for($account),
            'class_booking_id' => null,
            'provider' => 'liqpay',
            'payment_source' => CustomerPurchase::SourceOnlineCheckout,
            'order_id' => 'LP-'.Str::upper(Str::random(16)),
            'status' => 'payment_started',
            'plan_name' => $planName,
            'plan_slug' => Str::slug($planName),
            'schedule_kind' => 'group_class',
            'amount_cents' => fake()->numberBetween(100000, 500000),
            'currency' => 'UAH',
            'sessions_count' => fake()->randomElement([1, 4, 8, 12]),
            'validity_days' => 30,
            'total_validity_days' => 180,
            'started_at' => now(),
        ];
    }
}

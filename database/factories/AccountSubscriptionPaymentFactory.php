<?php

namespace Database\Factories;

use App\Models\AccountSubscriptionPayment;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccountSubscriptionPayment>
 */
class AccountSubscriptionPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_plan_id' => SubscriptionPlan::factory(),
            'provider' => 'monopay',
            'payment_type' => 'demo_initial',
            'order_id' => 'SAAS-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8)),
            'status' => 'payment_started',
            'amount_cents' => 100,
            'currency' => 'UAH',
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ];
    }
}

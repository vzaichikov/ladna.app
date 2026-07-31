<?php

namespace Database\Factories;

use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanSmsRateChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionPlanSmsRateChange>
 */
class SubscriptionPlanSmsRateChangeFactory extends Factory
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
            'actor_user_id' => null,
            'old_sms_segment_price_cents' => null,
            'new_sms_segment_price_cents' => fake()->randomElement([0, 132, 140]),
        ];
    }
}

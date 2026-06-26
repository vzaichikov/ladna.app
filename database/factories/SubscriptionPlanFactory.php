<?php

namespace Database\Factories;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubscriptionPlan>
 */
class SubscriptionPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['Starter', 'Studio', 'Growth']);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'description' => fake()->sentence(),
            'price_cents' => fake()->numberBetween(1900, 9900),
            'currency' => fake()->randomElement(['UAH', 'USD', 'EUR']),
            'billing_interval' => 'monthly',
            'plan_type' => 'standard',
            'access_days' => 30,
            'public_signup_enabled' => false,
            'requires_recurring_payment' => true,
            'renewal_lead_days' => 2,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(1, 10),
        ];
    }
}

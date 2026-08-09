<?php

namespace Database\Factories;

use App\Models\FestivalTariffPackage;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FestivalTariffPackage>
 */
class FestivalTariffPackageFactory extends Factory
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
            'name' => fake()->randomElement(['S', 'M', 'L']).'-'.fake()->unique()->numberBetween(100, 999),
            'price_cents' => 150000,
            'currency' => 'UAH',
            'max_participants' => 100,
            'max_tickets' => 300,
            'is_active' => true,
            'sort_order' => 10,
        ];
    }
}

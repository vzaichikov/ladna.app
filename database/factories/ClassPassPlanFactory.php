<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\ClassPassPlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ClassPassPlan>
 */
class ClassPassPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['START', 'AMATEUR', 'BASE', 'Semi pro', 'Pro']);

        return [
            'account_id' => Account::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'description' => fake()->sentence(),
            'price_cents' => fake()->numberBetween(120000, 500000),
            'currency' => 'UAH',
            'sessions_count' => fake()->randomElement([4, 6, 8, 12, 16]),
            'validity_days' => 30,
            'available_from_time' => null,
            'available_until_time' => null,
            'allows_any_time' => false,
            'any_time_addon_price_cents' => null,
            'is_trial' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}

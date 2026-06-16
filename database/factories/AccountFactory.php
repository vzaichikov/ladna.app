<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'status' => 'active',
            'default_language' => fake()->randomElement(['uk', 'en']),
            'default_currency' => fake()->randomElement(['UAH', 'USD', 'EUR']),
            'brand_color' => fake()->hexColor(),
            'timezone' => 'Europe/Kyiv',
        ];
    }
}

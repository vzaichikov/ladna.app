<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\TrainerType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainerType>
 */
class TrainerTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->unique()->randomElement(['Trainer', 'TOP-trainer', 'Senior trainer', 'Junior trainer']).' '.fake()->unique()->numberBetween(1, 999),
            'icon' => fake()->randomElement(array_keys(config('icons.trainer_types'))),
            'color' => fake()->hexColor(),
            'is_default' => false,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Trainer',
            'icon' => 'user-round',
            'color' => '#3B223F',
            'is_default' => true,
            'sort_order' => 10,
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Trainer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Trainer>
 */
class TrainerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->firstName();

        return [
            'account_id' => Account::factory(),
            'user_id' => null,
            'trainer_type_id' => null,
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'bio' => fake()->sentence(),
            'photo_path' => null,
            'is_active' => true,
        ];
    }
}

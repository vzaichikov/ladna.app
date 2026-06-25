<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ClassType>
 */
class ClassTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['Pole Beginner', 'Pole Choreo', 'Stretching', 'Exotic Flow', 'Strength & Conditioning']);

        return [
            'account_id' => Account::factory(),
            'activity_direction_id' => ActivityDirection::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'description' => fake()->sentence(),
            'color' => fake()->hexColor(),
            'schedule_kind' => 'group_class',
            'default_duration_minutes' => 60,
            'booking_cutoff_minutes' => 120,
            'cancellation_cutoff_minutes' => 1440,
            'default_capacity' => 12,
            'is_active' => true,
        ];
    }
}

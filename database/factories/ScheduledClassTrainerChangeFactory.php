<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\ScheduledClass;
use App\Models\ScheduledClassTrainerChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledClassTrainerChange>
 */
class ScheduledClassTrainerChangeFactory extends Factory
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
            'scheduled_class_id' => ScheduledClass::factory(),
            'previous_trainer_id' => null,
            'new_trainer_id' => null,
            'previous_trainer_name' => null,
            'new_trainer_name' => fake()->name(),
            'actor_user_id' => null,
            'actor_trainer_id' => null,
            'actor_name' => fake()->name(),
            'actor_email' => fake()->safeEmail(),
            'actor_role' => 'owner',
        ];
    }
}

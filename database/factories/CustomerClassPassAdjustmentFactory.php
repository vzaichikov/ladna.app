<?php

namespace Database\Factories;

use App\Enums\CustomerClassPassAdjustmentType;
use App\Models\Account;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerClassPassAdjustment>
 */
class CustomerClassPassAdjustmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $previousSessionsCount = fake()->numberBetween(1, 12);
        $sessionsDelta = fake()->numberBetween(1, 3);

        return [
            'account_id' => Account::factory(),
            'customer_class_pass_id' => CustomerClassPass::factory(),
            'user_id' => User::factory(),
            'actor_user_id' => null,
            'actor_trainer_id' => null,
            'actor_name' => fake()->name(),
            'actor_email' => fake()->safeEmail(),
            'actor_role' => 'owner',
            'adjustment_type' => CustomerClassPassAdjustmentType::Sessions->value,
            'sessions_delta' => $sessionsDelta,
            'previous_sessions_count' => $previousSessionsCount,
            'new_sessions_count' => $previousSessionsCount + $sessionsDelta,
            'days_delta' => null,
            'previous_validity_days' => null,
            'new_validity_days' => null,
            'previous_status' => null,
            'new_status' => null,
            'freeze_started_at' => null,
            'freeze_finished_at' => null,
            'freeze_days_count' => null,
            'reason' => fake()->sentence(),
        ];
    }
}

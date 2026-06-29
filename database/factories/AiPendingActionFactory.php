<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiPendingAction>
 */
class AiPendingActionFactory extends Factory
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
            'ai_conversation_id' => AiConversation::factory(),
            'user_id' => User::factory(),
            'trainer_id' => null,
            'action_name' => 'cancel-booking',
            'arguments' => ['booking_id' => fake()->numberBetween(1, 1000)],
            'preview' => ['summary' => fake()->sentence()],
            'status' => AiPendingAction::StatusPending,
            'result' => null,
            'error_message' => null,
            'expires_at' => now()->addMinutes(20),
            'confirmed_at' => null,
            'cancelled_at' => null,
            'executed_at' => null,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Enums\AiConversationMessageRole;
use App\Models\Account;
use App\Models\AiConversation;
use App\Models\AiConversationMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiConversationMessage>
 */
class AiConversationMessageFactory extends Factory
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
            'telegram_message_id' => null,
            'role' => AiConversationMessageRole::User->value,
            'content' => fake()->sentence(),
            'metadata' => null,
            'token_count' => null,
            'occurred_at' => now(),
        ];
    }
}

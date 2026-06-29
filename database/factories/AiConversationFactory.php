<?php

namespace Database\Factories;

use App\Enums\TelegramBotProfile;
use App\Models\Account;
use App\Models\AiConversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiConversation>
 */
class AiConversationFactory extends Factory
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
            'telegram_chat_authorization_id' => null,
            'user_id' => null,
            'trainer_id' => null,
            'channel' => 'telegram',
            'profile' => TelegramBotProfile::Owner->value,
            'status' => 'active',
            'title' => fake()->words(3, true),
            'last_message_at' => now(),
        ];
    }
}

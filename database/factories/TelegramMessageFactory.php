<?php

namespace Database\Factories;

use App\Enums\TelegramBotProfile;
use App\Models\Account;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramMessage>
 */
class TelegramMessageFactory extends Factory
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
            'telegram_bot_installation_id' => TelegramBotInstallation::factory(),
            'telegram_chat_authorization_id' => null,
            'telegram_update_id' => null,
            'profile' => TelegramBotProfile::Owner->value,
            'telegram_chat_id' => (string) fake()->numberBetween(100000, 999999),
            'telegram_message_id' => (string) fake()->numberBetween(100000, 999999),
            'telegram_user_id' => (string) fake()->numberBetween(100000, 999999),
            'direction' => 'inbound',
            'message_type' => 'text',
            'text' => fake()->sentence(),
            'payload' => null,
            'sent_at' => now(),
        ];
    }
}

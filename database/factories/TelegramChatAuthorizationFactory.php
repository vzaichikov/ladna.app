<?php

namespace Database\Factories;

use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\Account;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramChatAuthorization>
 */
class TelegramChatAuthorizationFactory extends Factory
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
            'user_id' => User::factory(),
            'trainer_id' => null,
            'profile' => TelegramBotProfile::Owner->value,
            'telegram_chat_id' => (string) fake()->numberBetween(100000, 999999),
            'telegram_user_id' => (string) fake()->numberBetween(100000, 999999),
            'telegram_username' => fake()->userName(),
            'phone' => '+380671112233',
            'status' => TelegramChatAuthorizationStatus::Authorized->value,
            'authorized_at' => now(),
            'revoked_at' => null,
        ];
    }
}

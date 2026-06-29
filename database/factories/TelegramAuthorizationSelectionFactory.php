<?php

namespace Database\Factories;

use App\Enums\TelegramBotProfile;
use App\Models\TelegramAuthorizationSelection;
use App\Models\TelegramBotInstallation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramAuthorizationSelection>
 */
class TelegramAuthorizationSelectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'telegram_bot_installation_id' => TelegramBotInstallation::factory(),
            'profile' => TelegramBotProfile::Owner->value,
            'telegram_chat_id' => (string) fake()->numberBetween(100000, 999999),
            'telegram_user_id' => (string) fake()->numberBetween(100000, 999999),
            'telegram_username' => fake()->userName(),
            'phone' => '+380671112233',
            'status' => TelegramAuthorizationSelection::StatusPending,
            'expires_at' => now()->addMinutes(10),
        ];
    }
}

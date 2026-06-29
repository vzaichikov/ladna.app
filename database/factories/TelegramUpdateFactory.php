<?php

namespace Database\Factories;

use App\Enums\TelegramBotProfile;
use App\Enums\TelegramUpdateStatus;
use App\Models\Account;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramUpdate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramUpdate>
 */
class TelegramUpdateFactory extends Factory
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
            'profile' => TelegramBotProfile::Owner->value,
            'update_id' => fake()->unique()->numberBetween(100000, 999999),
            'payload' => ['update_id' => fake()->numberBetween(100000, 999999)],
            'status' => TelegramUpdateStatus::Pending->value,
            'error_message' => null,
            'received_at' => now(),
            'processed_at' => null,
        ];
    }
}

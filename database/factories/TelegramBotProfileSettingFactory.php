<?php

namespace Database\Factories;

use App\Enums\TelegramBotMode;
use App\Enums\TelegramBotProfile;
use App\Models\Account;
use App\Models\TelegramBotProfileSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramBotProfileSetting>
 */
class TelegramBotProfileSettingFactory extends Factory
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
            'profile' => TelegramBotProfile::Owner->value,
            'mode' => TelegramBotMode::AiAssisted->value,
            'is_enabled' => true,
            'welcome_message' => null,
            'settings' => null,
        ];
    }
}

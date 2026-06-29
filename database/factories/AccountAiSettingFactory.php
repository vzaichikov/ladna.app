<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountAiSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountAiSetting>
 */
class AccountAiSettingFactory extends Factory
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
            'is_enabled' => false,
            'active_provider' => null,
            'active_model' => null,
            'bot_display_name' => 'Ladna assistant',
            'studio_ai_instructions' => null,
        ];
    }
}

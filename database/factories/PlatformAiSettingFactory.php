<?php

namespace Database\Factories;

use App\Enums\AiProvider;
use App\Models\PlatformAiSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformAiSetting>
 */
class PlatformAiSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_ai_assistant_enabled' => true,
            'active_provider' => AiProvider::OllamaCloud->value,
            'active_model' => 'gemma3:27b-cloud',
            'bot_display_name' => 'Ladna assistant',
            'internal_instructions' => 'Answer briefly.',
            'firewall_enabled' => true,
            'firewall_user_turns_per_minute' => 6,
            'firewall_user_turns_per_hour' => 30,
            'firewall_user_turns_per_day' => 100,
            'firewall_admin_turns_per_minute' => 20,
            'firewall_admin_turns_per_hour' => 100,
            'firewall_admin_turns_per_day' => 500,
            'firewall_account_turns_per_day' => 500,
            'firewall_user_provider_calls_per_hour' => 90,
            'firewall_user_provider_calls_per_day' => 300,
            'firewall_admin_provider_calls_per_hour' => 300,
            'firewall_admin_provider_calls_per_day' => 1500,
            'firewall_account_provider_calls_per_day' => 1500,
            'firewall_user_out_of_scope_streak' => 5,
            'firewall_admin_out_of_scope_streak' => 10,
            'firewall_cooldown_first_minutes' => 60,
            'firewall_cooldown_second_minutes' => 360,
            'firewall_cooldown_third_minutes' => 1440,
            'firewall_escalation_reset_days' => 7,
        ];
    }
}

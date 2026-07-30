<?php

namespace App\Models;

use App\Enums\AiProvider;
use Database\Factories\PlatformAiSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'owner_ai_assistant_enabled',
    'active_provider',
    'active_model',
    'bot_display_name',
    'internal_instructions',
    'firewall_enabled',
    'firewall_user_turns_per_minute',
    'firewall_user_turns_per_hour',
    'firewall_user_turns_per_day',
    'firewall_admin_turns_per_minute',
    'firewall_admin_turns_per_hour',
    'firewall_admin_turns_per_day',
    'firewall_account_turns_per_day',
    'firewall_user_provider_calls_per_hour',
    'firewall_user_provider_calls_per_day',
    'firewall_admin_provider_calls_per_hour',
    'firewall_admin_provider_calls_per_day',
    'firewall_account_provider_calls_per_day',
    'firewall_user_out_of_scope_streak',
    'firewall_admin_out_of_scope_streak',
    'firewall_cooldown_first_minutes',
    'firewall_cooldown_second_minutes',
    'firewall_cooldown_third_minutes',
    'firewall_escalation_reset_days',
])]
class PlatformAiSetting extends Model
{
    /** @use HasFactory<PlatformAiSettingFactory> */
    use HasFactory;

    protected $attributes = [
        'owner_ai_assistant_enabled' => false,
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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'owner_ai_assistant_enabled' => 'boolean',
            'firewall_enabled' => 'boolean',
            'active_provider' => AiProvider::class,
            'firewall_user_turns_per_minute' => 'integer',
            'firewall_user_turns_per_hour' => 'integer',
            'firewall_user_turns_per_day' => 'integer',
            'firewall_admin_turns_per_minute' => 'integer',
            'firewall_admin_turns_per_hour' => 'integer',
            'firewall_admin_turns_per_day' => 'integer',
            'firewall_account_turns_per_day' => 'integer',
            'firewall_user_provider_calls_per_hour' => 'integer',
            'firewall_user_provider_calls_per_day' => 'integer',
            'firewall_admin_provider_calls_per_hour' => 'integer',
            'firewall_admin_provider_calls_per_day' => 'integer',
            'firewall_account_provider_calls_per_day' => 'integer',
            'firewall_user_out_of_scope_streak' => 'integer',
            'firewall_admin_out_of_scope_streak' => 'integer',
            'firewall_cooldown_first_minutes' => 'integer',
            'firewall_cooldown_second_minutes' => 'integer',
            'firewall_cooldown_third_minutes' => 'integer',
            'firewall_escalation_reset_days' => 'integer',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'owner_ai_assistant_enabled' => false,
            'bot_display_name' => 'Ladna assistant',
        ]);
    }

    public static function ownerAssistantEnabled(): bool
    {
        $setting = self::query()->first();

        return (bool) $setting?->owner_ai_assistant_enabled;
    }

    public static function imageInferenceEnabled(): bool
    {
        $setting = self::query()->first();

        return (bool) $setting?->owner_ai_assistant_enabled
            && $setting?->active_provider?->supportsImageInference() === true;
    }
}

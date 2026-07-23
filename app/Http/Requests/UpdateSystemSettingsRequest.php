<?php

namespace App\Http\Requests;

use App\Enums\AiProvider;
use App\Enums\TelegramBotProfile;
use App\Models\TelegramBotInstallation;
use App\Support\AccountActivityLogSettings;
use App\Support\SystemAppearance;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSystemSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('accessPlatform') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'font_family' => ['required', Rule::in(array_keys(SystemAppearance::fontOptions()))],
            'support_url' => ['nullable', 'url', 'max:2048'],
            'activity_log_enabled' => ['nullable', 'boolean'],
            'activity_log_retention_days' => ['nullable', 'integer', 'min:'.AccountActivityLogSettings::MinRetentionDays, 'max:'.AccountActivityLogSettings::MaxRetentionDays],
            'owner_ai_assistant_enabled' => ['nullable', 'boolean'],
            'ai_active_provider' => ['nullable', Rule::in(array_column(AiProvider::cases(), 'value'))],
            'ai_bot_display_name' => ['nullable', 'string', 'max:80'],
            'ai_internal_instructions' => ['nullable', 'string', 'max:5000'],
            'ai_provider_models' => ['nullable', 'array'],
            'ai_provider_models.*' => ['nullable', 'string', 'max:120'],
            'ai_provider_credentials' => ['nullable', 'array'],
            'ai_provider_credentials.*' => ['nullable', 'string', 'max:4000'],
            'owner_telegram_bot_enabled' => ['nullable', 'boolean'],
            'owner_telegram_bot_token' => ['nullable', 'string', 'max:255'],
            'owner_telegram_bot_username' => ['nullable', 'string', 'max:255'],
            'founders_telegram_chat_id' => ['nullable', 'string', 'max:32', 'regex:/\A-?\d+\z/'],
            'founders_telegram_title' => ['nullable', 'string', 'max:255'],
            'founders_telegram_enabled' => ['nullable', 'boolean'],
            'settings_tab' => ['nullable', Rule::in(['appearance', 'support', 'activity-log', 'ai-owner'])],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $aiEnabled = filter_var($this->input('owner_ai_assistant_enabled', false), FILTER_VALIDATE_BOOLEAN);
                $activeProvider = (string) $this->input('ai_active_provider', '');

                if ($aiEnabled && $activeProvider === '') {
                    $validator->errors()->add('ai_active_provider', __('app.ai_provider_required'));
                }

                if ($activeProvider !== '' && blank($this->input("ai_provider_models.{$activeProvider}"))) {
                    $validator->errors()->add("ai_provider_models.{$activeProvider}", __('app.ai_model_required'));
                }
            },
            function (Validator $validator): void {
                if (! filter_var($this->input('owner_telegram_bot_enabled', false), FILTER_VALIDATE_BOOLEAN)) {
                    return;
                }

                $hasExistingToken = TelegramBotInstallation::query()
                    ->where('scope_type', 'platform')
                    ->where('scope_id', 0)
                    ->where('profile', TelegramBotProfile::Owner->value)
                    ->whereNotNull('encrypted_token')
                    ->exists();

                if (blank($this->input('owner_telegram_bot_token')) && ! $hasExistingToken) {
                    $validator->errors()->add('owner_telegram_bot_token', __('app.telegram_bot_token_required'));
                }
            },
            function (Validator $validator): void {
                if (! filter_var($this->input('founders_telegram_enabled', false), FILTER_VALIDATE_BOOLEAN)) {
                    return;
                }

                if (blank($this->input('founders_telegram_chat_id'))) {
                    $validator->errors()->add('founders_telegram_chat_id', __('app.telegram_founders_chat_id_required'));
                }

                if (blank($this->input('founders_telegram_title'))) {
                    $validator->errors()->add('founders_telegram_title', __('app.telegram_founders_title_required'));
                }
            },
        ];
    }
}

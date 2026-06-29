<?php

namespace App\Http\Requests;

use App\Enums\TelegramBotMode;
use App\Enums\TelegramBotProfile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountAiTelegramSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manageStudioSettings', $this->route('account')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'telegram_profiles' => ['nullable', 'array'],
            'telegram_profiles.customer.enabled' => ['nullable', 'boolean'],
            'telegram_profiles.customer.mode' => ['nullable', Rule::in([TelegramBotMode::Disabled->value, TelegramBotMode::Simple->value])],
            'telegram_profiles.customer.welcome_message' => ['nullable', 'string', 'max:1000'],
            'telegram_bots' => ['nullable', 'array'],
            'telegram_bots.customer.token' => ['nullable', 'string', 'max:255'],
            'telegram_bots.customer.bot_username' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $account = $this->route('account');
                $profile = TelegramBotProfile::Customer;

                if (! filter_var($this->input("telegram_profiles.{$profile->value}.enabled", false), FILTER_VALIDATE_BOOLEAN)) {
                    return;
                }

                $tokenInput = $this->input("telegram_bots.{$profile->value}.token");
                $hasExistingToken = $account?->telegramBotInstallations()
                    ->where('profile', $profile->value)
                    ->whereNotNull('encrypted_token')
                    ->exists() ?? false;

                if (blank($tokenInput) && ! $hasExistingToken) {
                    $validator->errors()->add("telegram_bots.{$profile->value}.token", __('app.telegram_bot_token_required'));
                }
            },
        ];
    }
}

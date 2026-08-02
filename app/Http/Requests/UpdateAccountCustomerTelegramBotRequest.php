<?php

namespace App\Http\Requests;

use App\Enums\TelegramBotProfile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountCustomerTelegramBotRequest extends FormRequest
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
            'token' => ['nullable', 'string', 'max:255', 'regex:/^\d+:[A-Za-z0-9_-]{20,}$/'],
            'welcome_message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $account = $this->route('account');
                $hasToken = $account?->telegramBotInstallations()
                    ->where('profile', TelegramBotProfile::Customer->value)
                    ->whereNotNull('encrypted_token')
                    ->exists() ?? false;

                if (blank($this->input('token')) && ! $hasToken) {
                    $validator->errors()->add('token', __('app.telegram_bot_token_required'));
                }
            },
        ];
    }
}

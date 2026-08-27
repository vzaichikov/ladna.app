<?php

namespace App\Http\Requests;

use App\Enums\TelegramBotProfile;
use App\Models\Account;
use App\Models\FestivalSeries;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFestivalTelegramBotRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');
        $series = $this->route('festivalSeries');

        return $account instanceof Account
            && $series instanceof FestivalSeries
            && (int) $series->account_id === (int) $account->id
            && ($this->user()?->can('manageFestivals', $account) ?? false)
            && ($this->user()?->can('manageStudioSettings', $account) ?? false);
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
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $account = $this->route('account');
                $series = $this->route('festivalSeries');
                $hasToken = $account instanceof Account && $series instanceof FestivalSeries
                    ? $account->telegramBotInstallations()
                        ->where('scope_type', 'festival_series')
                        ->where('scope_id', $series->id)
                        ->where('profile', TelegramBotProfile::Festival->value)
                        ->whereNotNull('encrypted_token')
                        ->exists()
                    : false;

                if (blank($this->input('token')) && ! $hasToken) {
                    $validator->errors()->add('token', __('app.telegram_bot_token_required'));
                }
            },
        ];
    }
}

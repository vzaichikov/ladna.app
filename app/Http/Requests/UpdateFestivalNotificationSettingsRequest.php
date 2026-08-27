<?php

namespace App\Http\Requests;

use App\Enums\FestivalNotificationType;
use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFestivalNotificationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account
            && (bool) $this->user()?->can('manageFestivals', $account);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $notificationTypes = collect(FestivalNotificationType::cases())
            ->map(fn (FestivalNotificationType $type): string => $type->value)
            ->implode(',');

        return [
            'sms' => ['sometimes', 'array:'.$notificationTypes],
            'sms.*' => ['boolean'],
            'telegram' => ['sometimes', 'array:'.$notificationTypes],
            'telegram.*' => ['boolean'],
            'owner_telegram' => ['sometimes', 'array:'.$notificationTypes],
            'owner_telegram.*' => ['boolean'],
        ];
    }
}

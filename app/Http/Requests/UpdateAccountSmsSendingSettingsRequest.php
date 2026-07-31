<?php

namespace App\Http\Requests;

use App\Enums\IntegrationProvider;
use App\Enums\SmsSendingMode;
use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountSmsSendingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && $account->isOwnedBy($this->user());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sms_sending_mode' => ['required', Rule::enum(SmsSendingMode::class)],
            'sms_provider' => [
                Rule::requiredIf($this->input('sms_sending_mode') === SmsSendingMode::OwnGateway->value),
                'nullable',
                Rule::in([
                    IntegrationProvider::Turbosms->value,
                    IntegrationProvider::Smsclub->value,
                    IntegrationProvider::Sendpulse->value,
                ]),
            ],
        ];
    }

    /**
     * @return array{sms_sending_mode: string, sms_provider: ?string}
     */
    public function payload(): array
    {
        $mode = SmsSendingMode::from((string) $this->validated('sms_sending_mode'));

        return [
            'sms_sending_mode' => $mode->value,
            'sms_provider' => $mode === SmsSendingMode::OwnGateway
                ? (string) $this->validated('sms_provider')
                : null,
        ];
    }
}

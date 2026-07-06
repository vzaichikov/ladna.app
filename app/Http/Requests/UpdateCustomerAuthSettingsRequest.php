<?php

namespace App\Http\Requests;

use App\Enums\CustomerOtpSenderScope;
use App\Enums\IntegrationProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerAuthSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('accessPlatform') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'allow_otp' => ['nullable', 'boolean'],
            'allow_rtsp_cameras' => ['nullable', 'boolean'],
            'enable_people_counter' => ['nullable', 'boolean'],
            'enable_telegram_alerts' => ['nullable', 'boolean'],
            'otp_sender_scope' => ['required', Rule::enum(CustomerOtpSenderScope::class)],
            'otp_provider' => [
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
     * @return array{allow_otp: bool, otp_sender_scope: string, otp_provider: ?string}
     */
    public function payload(): array
    {
        return [
            'allow_otp' => $this->boolean('allow_otp'),
            'otp_sender_scope' => (string) $this->validated('otp_sender_scope'),
            'otp_provider' => $this->validated('otp_provider'),
        ];
    }

    /**
     * @return array{allow_rtsp_cameras: bool, enable_people_counter: bool, enable_telegram_alerts: bool}
     */
    public function accountFeaturePayload(): array
    {
        return [
            'allow_rtsp_cameras' => $this->boolean('allow_rtsp_cameras'),
            'enable_people_counter' => $this->boolean('enable_people_counter'),
            'enable_telegram_alerts' => $this->boolean('enable_telegram_alerts'),
        ];
    }
}

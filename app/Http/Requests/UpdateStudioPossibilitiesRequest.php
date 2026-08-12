<?php

namespace App\Http\Requests;

use App\Enums\IntegrationProvider;
use App\Enums\SmsSendingMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudioPossibilitiesRequest extends FormRequest
{
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
            'allow_otp' => ['nullable', 'boolean'],
            'allow_rtsp_cameras' => ['nullable', 'boolean'],
            'enable_people_counter' => ['nullable', 'boolean'],
            'enable_customer_notifications' => ['nullable', 'boolean'],
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

    /** @return array{allow_otp: bool, sms_sending_mode: string, sms_provider: ?string} */
    public function customerAuthenticationPayload(): array
    {
        $mode = SmsSendingMode::from((string) $this->validated('sms_sending_mode'));

        return [
            'allow_otp' => $this->boolean('allow_otp'),
            'sms_sending_mode' => $mode->value,
            'sms_provider' => $mode === SmsSendingMode::OwnGateway
                ? (string) $this->validated('sms_provider')
                : null,
        ];
    }

    /** @return array{allow_rtsp_cameras: bool, enable_people_counter: bool, enable_customer_notifications: bool} */
    public function accountFeaturePayload(): array
    {
        return [
            'allow_rtsp_cameras' => $this->boolean('allow_rtsp_cameras'),
            'enable_people_counter' => $this->boolean('enable_people_counter'),
            'enable_customer_notifications' => $this->boolean('enable_customer_notifications'),
        ];
    }
}

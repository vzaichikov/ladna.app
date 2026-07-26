<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTrainerNotificationSettingsRequest extends FormRequest
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
            'enable_telegram_alerts' => ['nullable', 'boolean'],
            'trainer_assignment_enabled' => ['nullable', 'boolean'],
            'class_cancellation_enabled' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array{enable_telegram_alerts: bool, trainer_assignment_enabled: bool, class_cancellation_enabled: bool}
     */
    public function payload(): array
    {
        return [
            'enable_telegram_alerts' => $this->boolean('enable_telegram_alerts'),
            'trainer_assignment_enabled' => $this->boolean('trainer_assignment_enabled'),
            'class_cancellation_enabled' => $this->boolean('class_cancellation_enabled'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'enable_telegram_alerts' => $this->boolean('enable_telegram_alerts'),
            'trainer_assignment_enabled' => $this->boolean('trainer_assignment_enabled'),
            'class_cancellation_enabled' => $this->boolean('class_cancellation_enabled'),
        ]);
    }
}

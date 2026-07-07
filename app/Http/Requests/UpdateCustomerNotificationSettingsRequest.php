<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerNotificationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('account')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_enabled' => ['nullable', 'boolean'],
            'class_reminder_enabled' => ['nullable', 'boolean'],
            'class_reminder_hours_before' => ['required', 'integer', 'min:1', 'max:168'],
        ];
    }

    /**
     * @return array{is_enabled: bool, class_reminder_enabled: bool, class_reminder_hours_before: int}
     */
    public function payload(): array
    {
        return [
            'is_enabled' => $this->boolean('is_enabled'),
            'class_reminder_enabled' => $this->boolean('class_reminder_enabled'),
            'class_reminder_hours_before' => (int) $this->validated('class_reminder_hours_before'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_enabled' => $this->boolean('is_enabled'),
            'class_reminder_enabled' => $this->boolean('class_reminder_enabled'),
            'class_reminder_hours_before' => $this->input('class_reminder_hours_before', 5),
        ]);
    }
}

<?php

namespace App\Http\Requests;

use App\Support\AccountActivityLogSettings;
use App\Support\SystemAppearance;
use Illuminate\Contracts\Validation\ValidationRule;
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
            'settings_tab' => ['nullable', Rule::in(['appearance', 'support', 'activity-log'])],
        ];
    }
}

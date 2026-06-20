<?php

namespace App\Http\Requests;

use App\Enums\ScheduleKind;
use App\Models\ActivityDirection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClassTypeRequest extends FormRequest
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
        $account = $this->route('account');

        return [
            'activity_direction_id' => ['nullable', Rule::exists((new ActivityDirection)->getTable(), 'id')->where('account_id', $account?->id)],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'schedule_kind' => ['required', Rule::enum(ScheduleKind::class)],
            'default_duration_minutes' => ['required', 'integer', 'min:15', 'max:480'],
            'booking_cutoff_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'default_capacity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}

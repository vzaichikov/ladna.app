<?php

namespace App\Http\Requests;

use App\Support\ScheduleKindRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class UpdateAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
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
            'brand_tab' => ['nullable', Rule::in(['business', 'formats'])],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'default_language' => ['required', Rule::in(['uk', 'en'])],
            'country_code' => ['required', Rule::in(array_keys(config('charm.countries')))],
            'default_currency' => ['required', Rule::in(['UAH', 'USD', 'EUR'])],
            'brand_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => ['nullable', File::image()->types(['png', 'jpg', 'jpeg', 'webp'])->max('2mb')],
            'timezone' => ['nullable', 'timezone'],
            'enabled_schedule_kinds_present' => ['nullable', 'boolean'],
            'enabled_schedule_kinds' => ['required_if:enabled_schedule_kinds_present,1', 'array', 'min:1'],
            'enabled_schedule_kinds.*' => [Rule::in(ScheduleKindRegistry::defaultEnabledValues())],
            'schedule_kind_colors_present' => ['nullable', 'boolean'],
            'schedule_kind_colors' => ['required_if:schedule_kind_colors_present,1', 'array:'.implode(',', ScheduleKindRegistry::defaultEnabledValues())],
            'schedule_kind_colors.*' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $account = $this->route('account');

        $this->merge([
            'country_code' => $this->input('country_code') ?: ($account?->country_code ?? 'UA'),
        ]);

        if (! $this->has('enabled_schedule_kinds_present') && ! $this->has('enabled_schedule_kinds')) {
            $this->merge([
                'enabled_schedule_kinds' => $account?->enabledScheduleKindValues() ?? ScheduleKindRegistry::defaultEnabledValues(),
            ]);
        }

        if (! $this->has('schedule_kind_colors_present') && ! $this->has('schedule_kind_colors')) {
            $this->merge([
                'schedule_kind_colors' => $account?->scheduleKindColors() ?? ScheduleKindRegistry::defaultColors(),
            ]);
        }
    }
}

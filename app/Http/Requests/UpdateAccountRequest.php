<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Support\ScheduleKindRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
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
            'brand_tab' => ['nullable', Rule::in(['business', 'formats', 'opening_hours'])],
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
            'opening_hours_present' => ['nullable', 'boolean'],
            'opening_hours' => ['required_if:opening_hours_present,1', 'array:1,2,3,4,5,6,7'],
            'opening_hours.*' => ['array:enabled,opens_at,closes_at'],
            'opening_hours.*.enabled' => ['nullable', 'boolean'],
            'opening_hours.*.opens_at' => ['nullable', 'date_format:H:i'],
            'opening_hours.*.closes_at' => ['nullable', 'date_format:H:i'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->has('opening_hours_present')) {
                    return;
                }

                foreach (range(1, 7) as $weekday) {
                    $enabled = filter_var($this->input("opening_hours.{$weekday}.enabled", false), FILTER_VALIDATE_BOOLEAN);

                    if (! $enabled) {
                        continue;
                    }

                    $opensAt = $this->input("opening_hours.{$weekday}.opens_at");
                    $closesAt = $this->input("opening_hours.{$weekday}.closes_at");

                    if (! is_string($opensAt) || ! is_string($closesAt)) {
                        $validator->errors()->add("opening_hours.{$weekday}.opens_at", __('app.opening_hours_time_required'));

                        continue;
                    }

                    if (preg_match('/^\d{2}:\d{2}$/', $opensAt) !== 1 || preg_match('/^\d{2}:\d{2}$/', $closesAt) !== 1) {
                        continue;
                    }

                    if ($closesAt <= $opensAt) {
                        $validator->errors()->add("opening_hours.{$weekday}.closes_at", __('app.opening_hours_closes_after_opens'));
                    }
                }
            },
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

        if (! $this->has('opening_hours_present') && ! $this->has('opening_hours')) {
            $this->merge([
                'opening_hours' => $account?->openingHours() ?? Account::defaultOpeningHours(),
            ]);
        }
    }
}

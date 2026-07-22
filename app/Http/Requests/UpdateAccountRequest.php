<?php

namespace App\Http\Requests;

use App\Enums\PublicScheduleView;
use App\Models\Account;
use App\Rules\NotReservedPublicSlug;
use App\Rules\PublicSupportLink;
use App\Rules\PublicSupportPhone;
use App\Support\ScheduleKindRegistry;
use App\Support\StudioRulesHtmlSanitizer;
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
            'brand_tab' => ['nullable', Rule::in(['business', 'formats', 'opening_hours', 'rules', 'pass_rules', 'schedule_view'])],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', new NotReservedPublicSlug],
            'default_language' => ['required', Rule::in(['uk', 'en'])],
            'country_code' => ['required', Rule::in(array_keys(config('ladna.countries')))],
            'default_currency' => ['required', Rule::in(['UAH', 'USD', 'EUR'])],
            'brand_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'studio_slogan' => ['nullable', 'string', 'max:255'],
            'logo' => ['nullable', File::image()->types(['png'])->max('2mb')->dimensions(Rule::dimensions()->minWidth(512)->minHeight(512))],
            'timezone' => ['nullable', 'timezone'],
            'legal_entity_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:255'],
            'support_instagram_url' => ['nullable', 'string', 'max:2048', PublicSupportLink::instagram()],
            'support_telegram_url' => ['nullable', 'string', 'max:2048', PublicSupportLink::telegram()],
            'support_viber_url' => ['nullable', 'string', 'max:2048', PublicSupportLink::viber()],
            'support_whatsapp_url' => ['nullable', 'string', 'max:2048', PublicSupportLink::whatsapp()],
            'support_phone_url' => ['nullable', 'string', 'max:64', new PublicSupportPhone],
            'support_secondary_phone_url' => ['nullable', 'string', 'max:64', new PublicSupportPhone],
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
            'studio_rules_html' => ['nullable', 'string', 'max:50000'],
            'public_offer_html' => ['nullable', 'string', 'max:50000'],
            'class_pass_cancellation_rules_present' => ['nullable', 'boolean'],
            'class_pass_cancellation_rules' => ['required_if:class_pass_cancellation_rules_present,1', 'array:return_sessions_enabled,return_sessions_count,extend_days_enabled,extend_days_count'],
            'class_pass_cancellation_rules.return_sessions_enabled' => ['nullable', 'boolean'],
            'class_pass_cancellation_rules.return_sessions_count' => ['nullable', 'integer', 'min:1', 'max:999'],
            'class_pass_cancellation_rules.extend_days_enabled' => ['nullable', 'boolean'],
            'class_pass_cancellation_rules.extend_days_count' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'public_schedule_view' => ['nullable', Rule::in(PublicScheduleView::values())],
            'allow_guest_public_booking' => ['nullable', 'boolean'],
            'schedule_generation_weeks' => ['nullable', 'integer', 'min:'.Account::MIN_SCHEDULE_GENERATION_WEEKS, 'max:'.Account::MAX_SCHEDULE_GENERATION_WEEKS],
            'trainer_private_timeframes_enabled' => ['nullable', 'boolean'],
            'trainer_private_timeframe_weeks' => ['nullable', 'integer', 'min:'.Account::MIN_SCHEDULE_GENERATION_WEEKS, 'max:'.Account::MAX_SCHEDULE_GENERATION_WEEKS],
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
            function (Validator $validator): void {
                if (! $this->has('class_pass_cancellation_rules_present')) {
                    return;
                }

                $returnSessionsEnabled = filter_var($this->input('class_pass_cancellation_rules.return_sessions_enabled', false), FILTER_VALIDATE_BOOLEAN);
                $extendDaysEnabled = filter_var($this->input('class_pass_cancellation_rules.extend_days_enabled', false), FILTER_VALIDATE_BOOLEAN);

                if ($returnSessionsEnabled && blank($this->input('class_pass_cancellation_rules.return_sessions_count'))) {
                    $validator->errors()->add('class_pass_cancellation_rules.return_sessions_count', __('app.class_pass_cancellation_rule_count_required'));
                }

                if ($extendDaysEnabled && blank($this->input('class_pass_cancellation_rules.extend_days_count'))) {
                    $validator->errors()->add('class_pass_cancellation_rules.extend_days_count', __('app.class_pass_cancellation_rule_days_required'));
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $account = $this->route('account');

        $this->merge([
            'country_code' => $this->input('country_code') ?: ($account?->country_code ?? 'UA'),
            'allow_guest_public_booking' => filter_var($this->input('allow_guest_public_booking', $account?->allow_guest_public_booking ?? false), FILTER_VALIDATE_BOOLEAN),
            'trainer_private_timeframes_enabled' => filter_var($this->input('trainer_private_timeframes_enabled', $account?->trainer_private_timeframes_enabled ?? false), FILTER_VALIDATE_BOOLEAN),
            'trainer_private_timeframe_weeks' => blank($this->input('trainer_private_timeframe_weeks')) ? null : $this->input('trainer_private_timeframe_weeks'),
            ...$this->normalizedOptionalPublicFields(),
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

        if (! $this->has('class_pass_cancellation_rules_present') && ! $this->has('class_pass_cancellation_rules')) {
            $this->merge([
                'class_pass_cancellation_rules' => $account?->classPassCancellationRules() ?? Account::defaultClassPassCancellationRules(),
            ]);
        }

        if ($this->has('studio_rules_html') || $this->has('public_offer_html')) {
            $sanitizer = app(StudioRulesHtmlSanitizer::class);
            $legalDocuments = [];

            if ($this->has('studio_rules_html')) {
                $legalDocuments['studio_rules_html'] = $sanitizer->sanitize($this->input('studio_rules_html'));
            }

            if ($this->has('public_offer_html')) {
                $legalDocuments['public_offer_html'] = $sanitizer->sanitize($this->input('public_offer_html'));
            }

            $this->merge($legalDocuments);
        }

        if ($this->has('class_pass_cancellation_rules_present')) {
            $this->merge([
                'class_pass_cancellation_rules' => [
                    'return_sessions_enabled' => filter_var($this->input('class_pass_cancellation_rules.return_sessions_enabled', false), FILTER_VALIDATE_BOOLEAN),
                    'return_sessions_count' => $this->input('class_pass_cancellation_rules.return_sessions_count'),
                    'extend_days_enabled' => filter_var($this->input('class_pass_cancellation_rules.extend_days_enabled', false), FILTER_VALIDATE_BOOLEAN),
                    'extend_days_count' => $this->input('class_pass_cancellation_rules.extend_days_count'),
                ],
            ]);
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function normalizedOptionalPublicFields(): array
    {
        $normalized = [];

        foreach (['studio_slogan', 'support_instagram_url', 'support_telegram_url', 'support_viber_url', 'support_whatsapp_url', 'support_phone_url', 'support_secondary_phone_url'] as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->input($field);
            $normalized[$field] = blank($value) ? null : trim((string) $value);
        }

        return $normalized;
    }
}

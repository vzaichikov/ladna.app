<?php

namespace App\Http\Requests;

use App\Enums\ClassBookingStatus;
use App\Enums\SalaryClassFormulaType;
use App\Enums\SalaryModelType;
use App\Enums\SalaryPeriodUnit;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveSalaryModelRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account
            && ($this->user()?->can('manageStudioCashflow', $account) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');
        $allowedBookingStatuses = [
            ClassBookingStatus::Attended,
            ClassBookingStatus::Booked,
            ClassBookingStatus::NoShow,
        ];

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(SalaryModelType::class)],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'period_unit' => ['nullable', Rule::enum(SalaryPeriodUnit::class)],
            'amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:42949672.95'],
            'counted_booking_statuses' => ['nullable', 'array', 'min:1', 'max:3'],
            'counted_booking_statuses.*' => ['required', Rule::enum(ClassBookingStatus::class)->only($allowedBookingStatuses)],
            'pay_empty_classes' => ['nullable', 'boolean'],
            'rules' => ['nullable', 'array', 'min:1', 'max:101'],
            'rules.*.class_type_id' => [
                'nullable',
                'integer',
                'distinct:strict',
                Rule::exists((new ClassType)->getTable(), 'id')
                    ->where('account_id', $account instanceof Account ? $account->id : 0),
            ],
            'rules.*.is_default' => ['nullable', 'boolean'],
            'rules.*.formula_type' => ['required_with:rules', Rule::enum(SalaryClassFormulaType::class)],
            'rules.*.class_value_percentage' => ['nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:100'],
            'rules.*.flat_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:42949672.95'],
            'rules.*.person_rate' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:42949672.95'],
            'rules.*.minimum_people' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'rules.*.base_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:42949672.95'],
            'rules.*.included_people' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'rules.*.hourly_rate' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:42949672.95'],
            'rules.*.extra_person_rate' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:42949672.95'],
            'rules.*.minimum_pay' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:42949672.95'],
            'rules.*.maximum_pay' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:42949672.95'],
            'rules.*.tiers' => ['nullable', 'array', 'max:50'],
            'rules.*.tiers.*.minimum_people' => ['required_with:rules.*.tiers', 'integer', 'min:0', 'max:10000'],
            'rules.*.tiers.*.maximum_people' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'rules.*.tiers.*.amount' => ['required_with:rules.*.tiers', 'numeric', 'decimal:0,2', 'min:0', 'max:42949672.95'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $type = SalaryModelType::tryFrom((string) $this->input('type'));

                if ($type === SalaryModelType::FixedPeriod) {
                    if (blank($this->input('period_unit'))) {
                        $validator->errors()->add('period_unit', __('validation.required', ['attribute' => __('app.salary_period_unit')]));
                    }

                    if (blank($this->input('amount'))) {
                        $validator->errors()->add('amount', __('validation.required', ['attribute' => __('app.amount')]));
                    }

                    return;
                }

                if ($type !== SalaryModelType::PerClass) {
                    return;
                }

                $statuses = $this->input('counted_booking_statuses', []);
                if (! is_array($statuses) || $statuses === []) {
                    $validator->errors()->add('counted_booking_statuses', __('app.salary_booking_status_required'));
                }

                $rules = collect($this->input('rules', []));
                if ($rules->isEmpty()) {
                    $validator->errors()->add('rules', __('app.salary_default_rule_required'));

                    return;
                }

                $defaultRules = $rules->filter(fn (mixed $rule): bool => (bool) data_get($rule, 'is_default'));
                if ($defaultRules->count() !== 1 || filled(data_get($defaultRules->first(), 'class_type_id'))) {
                    $validator->errors()->add('rules', __('app.salary_exactly_one_default_rule'));
                }

                foreach ($rules->values() as $index => $rule) {
                    $this->validateFormula($validator, (int) $index, (array) $rule);
                }
            },
        ];
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        $this->selectTabForFirstRuleError($validator);

        parent::failedValidation($validator);
    }

    protected function prepareForValidation(): void
    {
        $isFixedPeriod = $this->input('type') === SalaryModelType::FixedPeriod->value;
        $rules = collect($this->input('rules', []))
            ->filter(fn (mixed $rule): bool => is_array($rule) && ! (bool) data_get($rule, '_remove'))
            ->map(function (array $rule): array {
                $rule['is_default'] = filter_var($rule['is_default'] ?? false, FILTER_VALIDATE_BOOL);
                $rule['tiers'] = ($rule['formula_type'] ?? null) === SalaryClassFormulaType::AttendanceTiers->value
                    ? collect($rule['tiers'] ?? [])
                        ->filter(fn (mixed $tier): bool => is_array($tier)
                            && ! (bool) data_get($tier, '_remove')
                            && (filled(data_get($tier, 'minimum_people'))
                                || filled(data_get($tier, 'maximum_people'))
                                || filled(data_get($tier, 'amount'))))
                        ->values()
                        ->all()
                    : [];

                return $rule;
            })
            ->values()
            ->all();

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'pay_empty_classes' => ! $isFixedPeriod && $this->boolean('pay_empty_classes'),
            'counted_booking_statuses' => $isFixedPeriod
                ? null
                : collect($this->input('counted_booking_statuses', []))
                    ->filter(fn (mixed $status): bool => filled($status))
                    ->unique()
                    ->values()
                    ->all(),
            'rules' => $isFixedPeriod ? null : $rules,
        ]);
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function validateFormula(Validator $validator, int $index, array $rule): void
    {
        $formulaType = SalaryClassFormulaType::tryFrom((string) ($rule['formula_type'] ?? ''));
        $requiredFields = match ($formulaType) {
            SalaryClassFormulaType::Flat => ['flat_amount'],
            SalaryClassFormulaType::PerPerson => ['person_rate'],
            SalaryClassFormulaType::BasePlusExtra => ['base_amount', 'extra_person_rate'],
            SalaryClassFormulaType::HourlyPlusExtra => ['hourly_rate', 'extra_person_rate'],
            SalaryClassFormulaType::AttendanceTiers => [],
            SalaryClassFormulaType::ClassValuePercentage => ['class_value_percentage'],
            default => [],
        };

        foreach ($requiredFields as $field) {
            if (blank($rule[$field] ?? null)) {
                $validator->errors()->add("rules.{$index}.{$field}", __('validation.required', ['attribute' => __('app.'.$field)]));
            }
        }

        $minimumPay = $rule['minimum_pay'] ?? null;
        $maximumPay = $rule['maximum_pay'] ?? null;
        if (filled($minimumPay) && filled($maximumPay) && (float) $minimumPay > (float) $maximumPay) {
            $validator->errors()->add("rules.{$index}.maximum_pay", __('app.salary_maximum_pay_must_cover_minimum'));
        }

        if ($formulaType !== SalaryClassFormulaType::AttendanceTiers) {
            return;
        }

        $tiers = collect($rule['tiers'] ?? [])->sortBy(fn (array $tier): int => (int) ($tier['minimum_people'] ?? -1))->values();
        if ($tiers->isEmpty()) {
            $validator->errors()->add("rules.{$index}.tiers", __('app.salary_tier_required'));

            return;
        }

        $expectedMinimum = 0;
        foreach ($tiers as $tierIndex => $tier) {
            $minimum = (int) ($tier['minimum_people'] ?? -1);
            $maximum = filled($tier['maximum_people'] ?? null) ? (int) $tier['maximum_people'] : null;

            if ($minimum !== $expectedMinimum) {
                $validator->errors()->add("rules.{$index}.tiers.{$tierIndex}.minimum_people", __('app.salary_tiers_must_be_continuous'));
            }

            if ($maximum !== null && $maximum < $minimum) {
                $validator->errors()->add("rules.{$index}.tiers.{$tierIndex}.maximum_people", __('app.salary_tier_maximum_invalid'));
            }

            if ($maximum === null && $tierIndex !== $tiers->count() - 1) {
                $validator->errors()->add("rules.{$index}.tiers.{$tierIndex}.maximum_people", __('app.salary_open_tier_must_be_last'));
            }

            $expectedMinimum = $maximum === null ? $minimum : $maximum + 1;
        }

        if (filled($tiers->last()['maximum_people'] ?? null)) {
            $validator->errors()->add("rules.{$index}.tiers", __('app.salary_last_tier_must_be_open'));
        }
    }

    private function selectTabForFirstRuleError(ValidatorContract $validator): void
    {
        $ruleErrorIndices = collect($validator->errors()->keys())
            ->map(function (string $key): ?int {
                preg_match('/^rules\.(\d+)\./', $key, $matches);

                return isset($matches[1]) ? (int) $matches[1] : null;
            })
            ->filter(fn (?int $index): bool => $index !== null)
            ->unique()
            ->values();
        $account = $this->route('account');

        if (! ($account instanceof Account)) {
            return;
        }

        foreach ($ruleErrorIndices as $ruleErrorIndex) {
            $classTypeId = data_get($this->input('rules'), $ruleErrorIndex.'.class_type_id');

            if (! filled($classTypeId)) {
                continue;
            }

            $scheduleKind = $account->classTypes()->whereKey($classTypeId)->value('schedule_kind');

            if ($scheduleKind instanceof ScheduleKind || is_string($scheduleKind)) {
                $this->session()->flash(
                    'salary_model_error_schedule_kind_tab',
                    $scheduleKind instanceof ScheduleKind ? $scheduleKind->value : $scheduleKind,
                );

                return;
            }
        }
    }
}

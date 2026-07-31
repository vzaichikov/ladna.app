@extends('layouts.app')

@section('title', ($salaryModel->exists ? __('app.edit_salary_model') : __('app.create_salary_model')).' - '.$account->name)

@section('content')
    @php
        $modelType = old('type', $salaryModel->type?->value ?? \App\Enums\SalaryModelType::PerClass->value);
        $storedRules = $version?->classRules?->keyBy(fn ($rule) => $rule->class_type_id ?? 'default') ?? collect();
        $toRuleValues = function ($rule, bool $remove = false) use ($centsToDecimal): array {
            if (! $rule) {
                return [
                    '_remove' => $remove,
                    'formula_type' => \App\Enums\SalaryClassFormulaType::Flat->value,
                    'class_value_percentage' => null,
                    'flat_amount' => '0.00',
                    'minimum_people' => 0,
                    'included_people' => 0,
                    'tiers' => [],
                ];
            }

            return [
                '_remove' => $remove,
                'formula_type' => $rule->formula_type->value,
                'class_value_percentage' => $rule->class_value_percentage_basis_points === null
                    ? null
                    : $centsToDecimal($rule->class_value_percentage_basis_points),
                'flat_amount' => $rule->flat_amount_cents === null ? null : $centsToDecimal($rule->flat_amount_cents),
                'person_rate' => $rule->person_rate_cents === null ? null : $centsToDecimal($rule->person_rate_cents),
                'minimum_people' => $rule->minimum_people,
                'base_amount' => $rule->base_amount_cents === null ? null : $centsToDecimal($rule->base_amount_cents),
                'included_people' => $rule->included_people,
                'hourly_rate' => $rule->hourly_rate_cents === null ? null : $centsToDecimal($rule->hourly_rate_cents),
                'extra_person_rate' => $rule->extra_person_rate_cents === null ? null : $centsToDecimal($rule->extra_person_rate_cents),
                'minimum_pay' => $rule->minimum_pay_cents === null ? null : $centsToDecimal($rule->minimum_pay_cents),
                'maximum_pay' => $rule->maximum_pay_cents === null ? null : $centsToDecimal($rule->maximum_pay_cents),
                'tiers' => $rule->tiers->map(fn ($tier) => [
                    'minimum_people' => $tier->minimum_people,
                    'maximum_people' => $tier->maximum_people,
                    'amount' => $centsToDecimal($tier->amount_cents),
                ])->all(),
            ];
        };
        $savedRuleRows = collect([
            [
                'class_type_id' => null,
                'key' => 'default',
                'schedule_kind' => null,
                'title' => __('app.salary_default_rule'),
                'is_default' => true,
                'values' => $toRuleValues($storedRules->get('default')),
            ],
        ])->concat($classTypes->map(fn ($classType) => [
            'class_type_id' => $classType->id,
            'key' => 'class:'.$classType->id,
            'schedule_kind' => $classType->schedule_kind->value,
            'title' => $classType->name,
            'is_default' => false,
            'values' => $toRuleValues($storedRules->get($classType->id), ! $storedRules->has($classType->id)),
        ]))->values();
        $submittedRules = old('rules');
        if (is_array($submittedRules)) {
            $submittedRuleRows = collect($submittedRules)->map(function ($rule, $index) use ($savedRuleRows) {
                $classTypeId = data_get($rule, 'class_type_id');
                $isDefault = (bool) data_get($rule, 'is_default');
                $key = $isDefault ? 'default' : 'class:'.$classTypeId;
                $metadata = $savedRuleRows->firstWhere('key', $key);

                return [
                    'key' => $key,
                    'rule_index' => (int) $index,
                    'class_type_id' => $classTypeId,
                    'title' => $metadata['title'] ?? __('app.class_type'),
                    'schedule_kind' => $metadata['schedule_kind'] ?? null,
                    'is_default' => $isDefault,
                    'values' => $rule,
                ];
            })->values();
            $submittedKeys = $submittedRuleRows->pluck('key');
            $nextRuleIndex = ((int) $submittedRuleRows->max('rule_index')) + 1;
            $missingRuleRows = $savedRuleRows
                ->reject(fn (array $row): bool => $submittedKeys->contains($row['key']))
                ->values()
                ->map(fn (array $row, int $index): array => [
                    ...$row,
                    'rule_index' => $nextRuleIndex + $index,
                ]);
            $ruleRows = $submittedRuleRows->concat($missingRuleRows)->values();
        } else {
            $ruleRows = $savedRuleRows
                ->map(fn (array $row, int $index): array => [...$row, 'rule_index' => $index])
                ->values();
        }
        $defaultRuleRow = $ruleRows->firstWhere('is_default', true);
        $overrideRuleRows = $ruleRows->reject(fn (array $row): bool => $row['is_default'])->values();
        $requestedSalaryTab = session('salary_model_error_schedule_kind_tab') ?: old('salary_schedule_kind_tab');
        $activeSalaryTab = is_string($requestedSalaryTab) && array_key_exists($requestedSalaryTab, $scheduleKindTabs)
            ? $requestedSalaryTab
            : (array_key_first($scheduleKindTabs) ?? 'group_class');
        $countedStatuses = old('counted_booking_statuses', $version?->countedBookingStatusValues() ?? [\App\Enums\ClassBookingStatus::Attended->value]);
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ $salaryModel->exists ? __('app.edit_salary_model') : __('app.create_salary_model') }}</h1>
            <p class="crm-page-copy">{{ __('app.salary_model_form_copy') }}</p>
        </div>
        <x-ui.button :href="route('dashboard.accounts.salary-models.index', $account)" variant="secondary">
            {{ __('app.salary_models') }}
        </x-ui.button>
    </div>

    @if ($errors->any())
        <div class="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
            <div class="font-semibold">{{ __('app.please_fix_errors') }}</div>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form
        method="POST"
        action="{{ $salaryModel->exists ? route('dashboard.accounts.salary-models.update', [$account, $salaryModel]) : route('dashboard.accounts.salary-models.store', $account) }}"
        class="mt-6 space-y-6"
        data-salary-model-form
        data-formula-descriptions="{{ collect($formulaTypes)->mapWithKeys(fn ($type) => [$type->value => __($type->descriptionKey())])->toJson() }}"
    >
        @csrf
        @if ($salaryModel->exists)
            @method('PUT')
        @endif

        <x-ui.panel>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block">
                    <span class="crm-label">{{ __('app.salary_model_name') }}</span>
                    <input name="name" value="{{ old('name', $salaryModel->name) }}" required maxlength="255" class="crm-field">
                    @error('name') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.effective_from') }}</span>
                    <input name="effective_from" type="date" value="{{ old('effective_from', $effectiveFromDefault) }}" required class="crm-field">
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.salary_effective_from_help') }}</span>
                    @error('effective_from') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
            </div>

            <fieldset class="mt-5">
                <legend class="crm-label">{{ __('app.salary_model_type') }}</legend>
                <div class="mt-2 grid gap-3 md:grid-cols-2">
                    @foreach ($modelTypes as $type)
                        <label @class([
                            'rounded-xl border p-4',
                            'cursor-pointer' => ! $salaryModel->exists,
                            'border-brand-200 bg-brand-50' => $modelType === $type->value,
                            'border-stone-200 bg-white' => $modelType !== $type->value,
                        ])>
                            <span class="flex items-start gap-3">
                                <input
                                    type="radio"
                                    name="type"
                                    value="{{ $type->value }}"
                                    class="mt-1 size-4 border-stone-300 text-brand-600 focus:ring-brand-500"
                                    data-salary-model-type
                                    @checked($modelType === $type->value)
                                    @disabled($salaryModel->exists)
                                >
                                <span>
                                    <span class="block font-semibold text-slate-950">{{ __($type->labelKey()) }}</span>
                                    <span class="mt-1 block text-sm leading-6 text-slate-500">{{ __($type->descriptionKey()) }}</span>
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
                @if ($salaryModel->exists)
                    <input type="hidden" name="type" value="{{ $modelType }}">
                @endif
            </fieldset>
        </x-ui.panel>

        <x-ui.panel data-salary-fixed-fields>
            <div>
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.salary_fixed_settings') }}</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.salary_fixed_settings_copy') }}</p>
            </div>
            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="crm-label">{{ __('app.salary_period_unit') }}</span>
                    <select name="period_unit" class="crm-field">
                        @foreach ($periodUnits as $unit)
                            <option value="{{ $unit->value }}" @selected(old('period_unit', $version?->period_unit?->value ?? \App\Enums\SalaryPeriodUnit::Month->value) === $unit->value)>
                                {{ __($unit->labelKey()) }}
                            </option>
                        @endforeach
                    </select>
                    @error('period_unit') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.amount') }} ({{ $account->default_currency ?: 'UAH' }})</span>
                    <input name="amount" type="number" min="0" step="0.01" value="{{ old('amount', $version?->amount_cents === null ? null : $centsToDecimal($version->amount_cents)) }}" class="crm-field">
                    @error('amount') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
            </div>
        </x-ui.panel>

        <div class="space-y-6" data-salary-per-class-fields>
            <x-ui.panel>
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('app.salary_attendance_settings') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.salary_attendance_settings_copy') }}</p>
                </div>
                <fieldset class="mt-5">
                    <legend class="crm-label">{{ __('app.salary_counted_statuses') }}</legend>
                    <div class="mt-2 flex flex-wrap gap-3">
                        @foreach ($bookingStatuses as $status)
                            <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700">
                                <input type="checkbox" name="counted_booking_statuses[]" value="{{ $status->value }}" class="size-4 rounded border-stone-300 text-brand-600 focus:ring-brand-500" @checked(in_array($status->value, $countedStatuses, true))>
                                {{ __('app.'.$status->value) }}
                            </label>
                        @endforeach
                    </div>
                    @error('counted_booking_statuses') <span class="crm-help">{{ $message }}</span> @enderror
                </fieldset>
                <label class="mt-5 flex cursor-pointer items-start gap-3">
                    <input type="hidden" name="pay_empty_classes" value="0">
                    <input type="checkbox" name="pay_empty_classes" value="1" class="mt-1 size-4 rounded border-stone-300 text-brand-600 focus:ring-brand-500" @checked(old('pay_empty_classes', $version?->pay_empty_classes ?? false))>
                    <span>
                        <span class="block font-semibold text-slate-950">{{ __('app.salary_pay_empty_classes') }}</span>
                        <span class="mt-1 block text-sm leading-6 text-slate-500">{{ __('app.salary_pay_empty_classes_copy') }}</span>
                    </span>
                </label>
            </x-ui.panel>

            @if ($defaultRuleRow)
                @include('reports._salary-rule', [
                    'ruleIndex' => $defaultRuleRow['rule_index'],
                    'rule' => $defaultRuleRow['values'],
                    'isDefault' => true,
                    'classTypeId' => null,
                    'ruleTitle' => $defaultRuleRow['title'],
                ])
            @endif

            <section
                class="space-y-4"
                data-salary-rule-tabs
                data-active-tab="{{ $activeSalaryTab }}"
            >
                <input type="hidden" name="salary_schedule_kind_tab" value="{{ $activeSalaryTab }}" data-salary-rule-active-tab>

                <div>
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('app.salary_class_overrides') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.salary_class_overrides_copy') }}</p>
                </div>

                <div class="overflow-x-auto pb-1">
                    <div class="flex min-w-max gap-1 rounded-lg bg-stone-100 p-1" role="tablist" aria-label="{{ __('app.salary_class_overrides') }}">
                        @foreach ($scheduleKindTabs as $scheduleKindValue => $scheduleKindDefinition)
                            <button
                                type="button"
                                id="salary-rule-tab-{{ $scheduleKindValue }}"
                                class="crm-tab whitespace-nowrap"
                                role="tab"
                                data-salary-rule-tab="{{ $scheduleKindValue }}"
                                aria-controls="salary-rule-panel-{{ $scheduleKindValue }}"
                                aria-selected="{{ $activeSalaryTab === $scheduleKindValue ? 'true' : 'false' }}"
                                tabindex="{{ $activeSalaryTab === $scheduleKindValue ? '0' : '-1' }}"
                            >
                                {{ __('app.'.$scheduleKindDefinition['title_key']) }}
                            </button>
                        @endforeach
                    </div>
                </div>

                @foreach ($scheduleKindTabs as $scheduleKindValue => $scheduleKindDefinition)
                    @php
                        $scheduleKindRuleRows = $overrideRuleRows->where('schedule_kind', $scheduleKindValue)->values();
                    @endphp
                    <section
                        id="salary-rule-panel-{{ $scheduleKindValue }}"
                        role="tabpanel"
                        data-salary-rule-panel="{{ $scheduleKindValue }}"
                        aria-labelledby="salary-rule-tab-{{ $scheduleKindValue }}"
                        @class(['hidden' => $activeSalaryTab !== $scheduleKindValue])
                    >
                        <div class="space-y-4">
                            @forelse ($scheduleKindRuleRows as $ruleRow)
                                @include('reports._salary-rule', [
                                    'ruleIndex' => $ruleRow['rule_index'],
                                    'rule' => $ruleRow['values'],
                                    'isDefault' => false,
                                    'classTypeId' => $ruleRow['class_type_id'],
                                    'ruleTitle' => $ruleRow['title'],
                                ])
                            @empty
                                <x-ui.empty-state :title="__('app.salary_no_class_types_for_kind')" icon="class-types" />
                            @endforelse
                        </div>
                    </section>
                @endforeach
            </section>
        </div>

        <div class="flex flex-wrap justify-end gap-2">
            <x-ui.button :href="route('dashboard.accounts.salary-models.index', $account)" variant="secondary">{{ __('app.cancel') }}</x-ui.button>
            <x-ui.button type="submit">{{ $salaryModel->exists ? __('app.create_salary_model_version') : __('app.create_salary_model') }}</x-ui.button>
        </div>
    </form>
@endsection

@php
    $rulePrefix = "rules[{$ruleIndex}]";
    $formulaValue = data_get($rule, 'formula_type', \App\Enums\SalaryClassFormulaType::Flat->value);
    $tierRows = collect(data_get($rule, 'tiers', []));
    if ($tierRows->isEmpty()) {
        $tierRows = collect([
            ['minimum_people' => 0, 'maximum_people' => 2, 'amount' => '0.00'],
            ['minimum_people' => 3, 'maximum_people' => null, 'amount' => '0.00'],
        ]);
    }
    $overrideEnabled = $isDefault || ! (bool) data_get($rule, '_remove', true);
@endphp

<section
    class="rounded-xl border border-stone-200 bg-white p-5 shadow-xs"
    data-salary-rule
    data-rule-index="{{ $ruleIndex }}"
>
    <input type="hidden" name="{{ $rulePrefix }}[is_default]" value="{{ $isDefault ? 1 : 0 }}">
    <input type="hidden" name="{{ $rulePrefix }}[class_type_id]" value="{{ $classTypeId }}">

    @if (! $isDefault)
        <input type="hidden" name="{{ $rulePrefix }}[_remove]" value="1">
        <label class="flex cursor-pointer items-start gap-3">
            <input
                type="checkbox"
                name="{{ $rulePrefix }}[_remove]"
                value="0"
                class="mt-1 size-4 rounded border-stone-300 text-brand-600 focus:ring-brand-500"
                data-salary-override-toggle
                @checked($overrideEnabled)
            >
            <span>
                <span class="block font-semibold text-slate-950">{{ $ruleTitle }}</span>
                <span class="mt-1 block text-sm leading-6 text-slate-500">{{ __('app.salary_class_override_copy') }}</span>
            </span>
        </label>
    @else
        <div>
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.salary_default_rule') }}</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.salary_default_rule_copy') }}</p>
        </div>
    @endif

    <div class="mt-5 space-y-5" data-salary-rule-fields>
        <label class="block">
            <span class="crm-label">{{ __('app.salary_formula') }}</span>
            <select name="{{ $rulePrefix }}[formula_type]" class="crm-field" data-salary-formula>
                @foreach ($formulaTypes as $formulaType)
                    <option value="{{ $formulaType->value }}" @selected($formulaValue === $formulaType->value)>
                        {{ __($formulaType->labelKey()) }}
                    </option>
                @endforeach
            </select>
            <span class="mt-1 block text-xs leading-5 text-slate-500" data-salary-formula-description></span>
        </label>

        <div class="grid gap-4 sm:grid-cols-2" data-salary-formula-fields="flat">
            <label class="block">
                <span class="crm-label">{{ __('app.flat_amount') }}</span>
                <input name="{{ $rulePrefix }}[flat_amount]" type="number" min="0" step="0.01" value="{{ data_get($rule, 'flat_amount') }}" class="crm-field">
                @error("rules.{$ruleIndex}.flat_amount") <span class="crm-help">{{ $message }}</span> @enderror
            </label>
        </div>

        <div class="grid gap-4 sm:grid-cols-2" data-salary-formula-fields="class_value_percentage">
            <label class="block">
                <span class="crm-label">{{ __('app.class_value_percentage') }}</span>
                <div class="relative">
                    <input
                        name="{{ $rulePrefix }}[class_value_percentage]"
                        type="number"
                        min="0.01"
                        max="100"
                        step="0.01"
                        value="{{ data_get($rule, 'class_value_percentage') }}"
                        class="crm-field pr-10"
                    >
                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm font-semibold text-slate-500">%</span>
                </div>
                <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.class_value_percentage_help') }}</span>
                @error("rules.{$ruleIndex}.class_value_percentage") <span class="crm-help">{{ $message }}</span> @enderror
            </label>
        </div>

        <div class="grid gap-4 sm:grid-cols-2" data-salary-formula-fields="per_person">
            <label class="block">
                <span class="crm-label">{{ __('app.person_rate') }}</span>
                <input name="{{ $rulePrefix }}[person_rate]" type="number" min="0" step="0.01" value="{{ data_get($rule, 'person_rate') }}" class="crm-field">
                @error("rules.{$ruleIndex}.person_rate") <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.minimum_people') }}</span>
                <input name="{{ $rulePrefix }}[minimum_people]" type="number" min="0" value="{{ data_get($rule, 'minimum_people', 0) }}" class="crm-field">
                <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.minimum_people_salary_help') }}</span>
            </label>
        </div>

        <div class="grid gap-4 sm:grid-cols-3" data-salary-formula-fields="base_plus_extra">
            <label class="block">
                <span class="crm-label">{{ __('app.base_amount') }}</span>
                <input name="{{ $rulePrefix }}[base_amount]" type="number" min="0" step="0.01" value="{{ data_get($rule, 'base_amount') }}" class="crm-field">
                @error("rules.{$ruleIndex}.base_amount") <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.included_people') }}</span>
                <input name="{{ $rulePrefix }}[included_people]" type="number" min="0" value="{{ data_get($rule, 'included_people', 0) }}" class="crm-field">
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.extra_person_rate') }}</span>
                <input name="{{ $rulePrefix }}[extra_person_rate]" type="number" min="0" step="0.01" value="{{ data_get($rule, 'extra_person_rate') }}" class="crm-field">
                @error("rules.{$ruleIndex}.extra_person_rate") <span class="crm-help">{{ $message }}</span> @enderror
            </label>
        </div>

        <div class="grid gap-4 sm:grid-cols-3" data-salary-formula-fields="hourly_plus_extra">
            <label class="block">
                <span class="crm-label">{{ __('app.hourly_rate') }}</span>
                <input name="{{ $rulePrefix }}[hourly_rate]" type="number" min="0" step="0.01" value="{{ data_get($rule, 'hourly_rate') }}" class="crm-field">
                @error("rules.{$ruleIndex}.hourly_rate") <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.included_people') }}</span>
                <input name="{{ $rulePrefix }}[included_people]" type="number" min="0" value="{{ data_get($rule, 'included_people', 0) }}" class="crm-field">
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.extra_person_rate') }}</span>
                <input name="{{ $rulePrefix }}[extra_person_rate]" type="number" min="0" step="0.01" value="{{ data_get($rule, 'extra_person_rate') }}" class="crm-field">
                @error("rules.{$ruleIndex}.extra_person_rate") <span class="crm-help">{{ $message }}</span> @enderror
            </label>
        </div>

        <div data-salary-formula-fields="attendance_tiers">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="font-semibold text-slate-950">{{ __('app.salary_attendance_tiers') }}</h3>
                    <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.salary_attendance_tiers_copy') }}</p>
                </div>
                <x-ui.button type="button" variant="secondary" size="sm" data-salary-add-tier>
                    {{ __('app.add_tier') }}
                </x-ui.button>
            </div>
            @error("rules.{$ruleIndex}.tiers") <div class="mt-3 text-sm font-semibold text-rose-700">{{ $message }}</div> @enderror
            <div class="mt-4 space-y-3" data-salary-tier-rows>
                @foreach ($tierRows as $tier)
                    <div class="grid gap-3 rounded-xl border border-stone-200 bg-slate-50 p-4 sm:grid-cols-[1fr_1fr_1.4fr_auto]" data-salary-tier-row>
                        <label>
                            <span class="crm-label">{{ __('app.minimum_people') }}</span>
                            <input type="number" min="0" value="{{ data_get($tier, 'minimum_people') }}" class="crm-field" data-salary-tier-field="minimum_people">
                        </label>
                        <label>
                            <span class="crm-label">{{ __('app.maximum_people') }}</span>
                            <input type="number" min="0" value="{{ data_get($tier, 'maximum_people') }}" class="crm-field" data-salary-tier-field="maximum_people">
                        </label>
                        <label>
                            <span class="crm-label">{{ __('app.amount') }}</span>
                            <input type="number" min="0" step="0.01" value="{{ data_get($tier, 'amount') }}" class="crm-field" data-salary-tier-field="amount">
                        </label>
                        <button type="button" class="self-end rounded-lg px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50" data-salary-remove-tier>{{ __('app.remove') }}</button>
                    </div>
                @endforeach
            </div>
            <template data-salary-tier-template>
                <div class="grid gap-3 rounded-xl border border-stone-200 bg-slate-50 p-4 sm:grid-cols-[1fr_1fr_1.4fr_auto]" data-salary-tier-row>
                    <label><span class="crm-label">{{ __('app.minimum_people') }}</span><input type="number" min="0" class="crm-field" data-salary-tier-field="minimum_people"></label>
                    <label><span class="crm-label">{{ __('app.maximum_people') }}</span><input type="number" min="0" class="crm-field" data-salary-tier-field="maximum_people"></label>
                    <label><span class="crm-label">{{ __('app.amount') }}</span><input type="number" min="0" step="0.01" class="crm-field" data-salary-tier-field="amount"></label>
                    <button type="button" class="self-end rounded-lg px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50" data-salary-remove-tier>{{ __('app.remove') }}</button>
                </div>
            </template>
        </div>

        <div class="grid gap-4 border-t border-stone-100 pt-5 sm:grid-cols-2">
            <label class="block">
                <span class="crm-label">{{ __('app.minimum_pay') }}</span>
                <input name="{{ $rulePrefix }}[minimum_pay]" type="number" min="0" step="0.01" value="{{ data_get($rule, 'minimum_pay') }}" class="crm-field">
                <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.salary_limit_optional_help') }}</span>
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.maximum_pay') }}</span>
                <input name="{{ $rulePrefix }}[maximum_pay]" type="number" min="0" step="0.01" value="{{ data_get($rule, 'maximum_pay') }}" class="crm-field">
                @error("rules.{$ruleIndex}.maximum_pay") <span class="crm-help">{{ $message }}</span> @enderror
            </label>
        </div>
    </div>
</section>

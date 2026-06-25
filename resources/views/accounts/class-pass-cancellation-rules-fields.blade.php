@php
    $classPassCancellationRules = old('class_pass_cancellation_rules', $account->classPassCancellationRules());
    $returnSessionsEnabled = filter_var(data_get($classPassCancellationRules, 'return_sessions_enabled', false), FILTER_VALIDATE_BOOLEAN);
    $extendDaysEnabled = filter_var(data_get($classPassCancellationRules, 'extend_days_enabled', false), FILTER_VALIDATE_BOOLEAN);
@endphp

<input type="hidden" name="brand_tab" value="pass_rules">
<input type="hidden" name="name" value="{{ $account->name }}">
<input type="hidden" name="slug" value="{{ $account->slug }}">
<input type="hidden" name="default_language" value="{{ $account->default_language }}">
<input type="hidden" name="country_code" value="{{ $account->country_code ?? 'UA' }}">
<input type="hidden" name="default_currency" value="{{ $account->default_currency }}">
<input type="hidden" name="brand_color" value="{{ $account->brand_color }}">
<input type="hidden" name="timezone" value="{{ $account->timezone }}">
<input type="hidden" name="class_pass_cancellation_rules_present" value="1">

<fieldset>
    <legend class="crm-label">{{ __('app.class_pass_rules_on_delete') }}</legend>
    <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.class_pass_rules_on_delete_help') }}</p>

    <div class="mt-4 space-y-3">
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
            <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_9rem] sm:items-end">
                <label class="flex min-w-0 items-start gap-3 text-sm font-medium text-slate-700">
                    <input type="hidden" name="class_pass_cancellation_rules[return_sessions_enabled]" value="0">
                    <input
                        name="class_pass_cancellation_rules[return_sessions_enabled]"
                        type="checkbox"
                        value="1"
                        @checked($returnSessionsEnabled)
                        class="crm-checkbox mt-0.5"
                    >
                    <span class="min-w-0">
                        <span class="block text-slate-950">{{ __('app.return_cancelled_class_sessions') }}</span>
                        <span class="mt-0.5 block text-xs leading-5 text-slate-500">{{ __('app.return_cancelled_class_sessions_help') }}</span>
                    </span>
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.sessions_count') }}</span>
                    <input
                        name="class_pass_cancellation_rules[return_sessions_count]"
                        type="number"
                        min="1"
                        max="999"
                        value="{{ data_get($classPassCancellationRules, 'return_sessions_count', 1) }}"
                        class="crm-field"
                    >
                    @error('class_pass_cancellation_rules.return_sessions_count') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
            <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_9rem] sm:items-end">
                <label class="flex min-w-0 items-start gap-3 text-sm font-medium text-slate-700">
                    <input type="hidden" name="class_pass_cancellation_rules[extend_days_enabled]" value="0">
                    <input
                        name="class_pass_cancellation_rules[extend_days_enabled]"
                        type="checkbox"
                        value="1"
                        @checked($extendDaysEnabled)
                        class="crm-checkbox mt-0.5"
                    >
                    <span class="min-w-0">
                        <span class="block text-slate-950">{{ __('app.extend_cancelled_class_pass_days') }}</span>
                        <span class="mt-0.5 block text-xs leading-5 text-slate-500">{{ __('app.extend_cancelled_class_pass_days_help') }}</span>
                    </span>
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.days_count') }}</span>
                    <input
                        name="class_pass_cancellation_rules[extend_days_count]"
                        type="number"
                        min="1"
                        max="3650"
                        value="{{ data_get($classPassCancellationRules, 'extend_days_count', 1) }}"
                        class="crm-field"
                    >
                    @error('class_pass_cancellation_rules.extend_days_count') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
            </div>
        </div>
    </div>

    @error('class_pass_cancellation_rules') <span class="crm-help">{{ $message }}</span> @enderror
</fieldset>

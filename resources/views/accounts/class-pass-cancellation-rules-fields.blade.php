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
<input type="hidden" name="allow_guest_public_booking" value="0">

<fieldset class="rounded-lg border border-stone-200 bg-slate-50 p-4">
    <legend class="crm-label px-1">{{ __('app.class_pass_rules_on_delete') }}</legend>
    <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.class_pass_rules_on_delete_help') }}</p>

    <div class="mt-4 space-y-3">
        <div class="rounded-lg border border-slate-200 bg-white p-4">
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
                    <span class="crm-label">{{ __('app.bonus_sessions_count') }}</span>
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

        <div class="rounded-lg border border-slate-200 bg-white p-4">
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
                    <span class="crm-label">{{ __('app.extension_days_count') }}</span>
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

<fieldset class="rounded-lg border border-stone-200 bg-slate-50 p-4">
    <legend class="crm-label px-1">{{ __('app.schedule_generation_policy') }}</legend>
    <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.schedule_generation_policy_help') }}</p>

    <div class="mt-4 grid gap-4 sm:grid-cols-[minmax(0,1fr)_9rem] sm:items-end">
        <div class="text-sm leading-6 text-slate-500">
            {{ __('app.schedule_generation_weeks_default', ['weeks' => \App\Models\Account::defaultScheduleGenerationWeeks()]) }}
        </div>
        <label class="block">
            <span class="crm-label">{{ __('app.schedule_generation_weeks') }}</span>
            <input
                name="schedule_generation_weeks"
                type="number"
                min="{{ \App\Models\Account::MIN_SCHEDULE_GENERATION_WEEKS }}"
                max="{{ \App\Models\Account::MAX_SCHEDULE_GENERATION_WEEKS }}"
                value="{{ old('schedule_generation_weeks', $account->scheduleGenerationWeeks()) }}"
                class="crm-field"
            >
            @error('schedule_generation_weeks') <span class="crm-help">{{ $message }}</span> @enderror
        </label>
    </div>
</fieldset>

<fieldset class="rounded-lg border border-stone-200 bg-slate-50 p-4">
    <legend class="crm-label px-1">{{ __('app.public_booking_policy') }}</legend>
    <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.public_booking_policy_help') }}</p>

    <label class="mt-4 flex min-w-0 items-start gap-3 text-sm font-medium text-slate-700">
        <input
            name="allow_guest_public_booking"
            type="checkbox"
            value="1"
            @checked(old('allow_guest_public_booking', $account->allow_guest_public_booking))
            class="crm-checkbox mt-0.5"
        >
        <span class="min-w-0">
            <span class="block text-slate-950">{{ __('app.allow_guest_public_booking') }}</span>
            <span class="mt-0.5 block text-xs leading-5 text-slate-500">{{ __('app.allow_guest_public_booking_help') }}</span>
        </span>
    </label>
</fieldset>

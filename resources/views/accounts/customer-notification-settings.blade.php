@php
    $notificationSetting = $customerNotificationSetting ?? new \App\Models\CustomerNotificationSetting([
        'account_id' => $account->id,
    ]);
@endphp

<form method="POST" action="{{ route('dashboard.accounts.customer-notification-settings.update', $account) }}" class="mt-6 max-w-4xl space-y-6">
    @csrf
    @method('PUT')

    <section class="rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
        <div>
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.customer_notifications') }}</h2>
            <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.customer_notifications_copy') }}</p>
        </div>

        <label class="mt-6 flex items-start gap-3 rounded-lg border border-violet-200 bg-violet-50 px-4 py-4 text-sm font-semibold text-slate-900">
            <input type="hidden" name="is_enabled" value="0">
            <input name="is_enabled" type="checkbox" value="1" @checked(old('is_enabled', $notificationSetting->is_enabled)) class="crm-checkbox mt-0.5">
            <span class="grid gap-1">
                <span>{{ __('app.customer_notifications_master_title') }}</span>
                <span class="text-sm font-normal leading-6 text-slate-600">{{ __('app.customer_notifications_enable_hint') }}</span>
            </span>
        </label>

        <div class="mt-6 border-t border-stone-100 pt-6">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('app.notification_scenarios') }}</h3>

            <section class="mt-4 rounded-lg border border-stone-200 bg-slate-50 px-4 py-4">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <label class="flex items-start gap-3 text-sm font-semibold text-slate-900">
                        <input type="hidden" name="class_reminder_enabled" value="0">
                        <input name="class_reminder_enabled" type="checkbox" value="1" @checked(old('class_reminder_enabled', $notificationSetting->class_reminder_enabled)) class="crm-checkbox mt-0.5">
                        <span class="grid gap-1">
                            <span>{{ __('app.customer_notification_class_reminder') }}</span>
                            <span class="text-sm font-normal leading-6 text-slate-600">{{ __('app.customer_notification_class_reminder_hint') }}</span>
                        </span>
                    </label>

                    <label class="block sm:w-44">
                        <span class="crm-label">{{ __('app.customer_notification_hours_before') }}</span>
                        <input name="class_reminder_hours_before" type="number" min="1" max="168" step="1" value="{{ old('class_reminder_hours_before', $notificationSetting->class_reminder_hours_before ?? 5) }}" class="crm-field">
                        @error('class_reminder_hours_before') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                </div>
            </section>

            <label class="mt-4 flex items-start gap-3 rounded-lg border border-stone-200 bg-slate-50 px-4 py-4 text-sm font-semibold text-slate-900">
                <input type="hidden" name="class_cancellation_enabled" value="0">
                <input name="class_cancellation_enabled" type="checkbox" value="1" @checked(old('class_cancellation_enabled', $notificationSetting->class_cancellation_enabled)) class="crm-checkbox mt-0.5">
                <span class="grid gap-1">
                    <span>{{ __('app.customer_notification_class_cancellation') }}</span>
                    <span class="text-sm font-normal leading-6 text-slate-600">{{ __('app.customer_notification_class_cancellation_hint') }}</span>
                </span>
            </label>
        </div>
    </section>

    <x-ui.button type="submit">
        <x-ui.icon name="save" class="h-4 w-4" />
        {{ __('app.save_changes') }}
    </x-ui.button>
</form>

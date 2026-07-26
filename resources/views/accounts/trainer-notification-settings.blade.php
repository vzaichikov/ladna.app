<form method="POST" action="{{ route('dashboard.accounts.trainer-notification-settings.update', $account) }}" class="mt-6 max-w-4xl space-y-6">
    @csrf
    @method('PUT')

    <section class="rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
        <div>
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.notifications_trainers') }}</h2>
            <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.trainer_notifications_copy') }}</p>
        </div>

        <label class="mt-6 flex items-start gap-3 rounded-lg border border-violet-200 bg-violet-50 px-4 py-4 text-sm font-semibold text-slate-900">
            <input type="hidden" name="enable_telegram_alerts" value="0">
            <input name="enable_telegram_alerts" type="checkbox" value="1" @checked(old('enable_telegram_alerts', $account->enable_telegram_alerts)) class="crm-checkbox mt-0.5">
            <span class="grid gap-1">
                <span>{{ __('app.trainer_notifications_master_title') }}</span>
                <span class="text-sm font-normal leading-6 text-slate-600">{{ __('app.trainer_notifications_master_hint') }}</span>
            </span>
        </label>

        <div class="mt-6 border-t border-stone-100 pt-6">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('app.notification_scenarios') }}</h3>

            <label class="mt-4 flex items-start gap-3 rounded-lg border border-stone-200 bg-slate-50 px-4 py-4 text-sm font-semibold text-slate-900">
                <input type="hidden" name="trainer_assignment_enabled" value="0">
                <input name="trainer_assignment_enabled" type="checkbox" value="1" @checked(old('trainer_assignment_enabled', $trainerNotificationSetting->trainer_assignment_enabled)) class="crm-checkbox mt-0.5">
                <span class="grid gap-1">
                    <span>{{ __('app.trainer_notification_assignment') }}</span>
                    <span class="text-sm font-normal leading-6 text-slate-600">{{ __('app.trainer_notification_assignment_hint') }}</span>
                </span>
            </label>

            <label class="mt-4 flex items-start gap-3 rounded-lg border border-stone-200 bg-slate-50 px-4 py-4 text-sm font-semibold text-slate-900">
                <input type="hidden" name="class_cancellation_enabled" value="0">
                <input name="class_cancellation_enabled" type="checkbox" value="1" @checked(old('class_cancellation_enabled', $trainerNotificationSetting->class_cancellation_enabled)) class="crm-checkbox mt-0.5">
                <span class="grid gap-1">
                    <span>{{ __('app.trainer_notification_class_cancellation') }}</span>
                    <span class="text-sm font-normal leading-6 text-slate-600">{{ __('app.trainer_notification_class_cancellation_hint') }}</span>
                </span>
            </label>
        </div>
    </section>

    <x-ui.button type="submit">
        <x-ui.icon name="save" class="h-4 w-4" />
        {{ __('app.save_changes') }}
    </x-ui.button>
</form>

@props(['account', 'edition', 'workflow', 'step'])

@php
    $configuredNotifications = (array) data_get($step->config, 'completion_notifications', []);
    $channels = [
        'email' => ['rows' => 6, 'maxlength' => 5000, 'help' => 'festival_completion_notification_email_help'],
        'sms' => ['rows' => 4, 'maxlength' => 1000, 'help' => 'festival_completion_notification_sms_help'],
        'telegram' => ['rows' => 5, 'maxlength' => 3000, 'help' => 'festival_completion_notification_telegram_help'],
    ];
@endphp

<form method="POST" action="{{ route('dashboard.accounts.festivals.workflow-steps.completion-notifications.update', [$account, $edition, $workflow, $step]) }}" class="space-y-6">
    @csrf
    @method('PUT')

    <div>
        <h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_completion_notifications_title') }}</h2>
        <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('app.festival_completion_notifications_copy') }}</p>
        <p class="mt-3 rounded-xl border border-violet-200 bg-violet-50 p-4 text-sm leading-6 text-violet-950">
            {{ __('app.festival_completion_notification_placeholders_intro') }}
            <code class="font-semibold">%name%</code> — {{ __('app.festival_completion_notification_placeholder_name') }};
            <code class="font-semibold">%category%</code> — {{ __('app.festival_completion_notification_placeholder_category') }}.
        </p>
    </div>

    <div class="grid gap-5 xl:grid-cols-2">
        @foreach (['uk', 'en'] as $locale)
            <section class="rounded-xl border border-stone-200 bg-stone-50 p-4 sm:p-5" aria-labelledby="festival-completion-notifications-{{ $locale }}">
                <h3 id="festival-completion-notifications-{{ $locale }}" class="text-base font-semibold text-slate-950">
                    {{ __('app.festival_completion_notification_language_'.$locale) }}
                </h3>
                <div class="mt-4 grid gap-4">
                    @foreach ($channels as $channel => $settings)
                        @php($field = 'completion_notifications.'.$locale.'.'.$channel)
                        <label class="block">
                            <span class="crm-label">{{ __('app.festival_notification_channel_'.$channel) }}</span>
                            <textarea name="completion_notifications[{{ $locale }}][{{ $channel }}]" rows="{{ $settings['rows'] }}" maxlength="{{ $settings['maxlength'] }}" class="crm-field" placeholder="{{ __('app.festival_completion_notification_default_placeholder') }}">{{ old($field, data_get($configuredNotifications, $locale.'.'.$channel)) }}</textarea>
                            <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.'.$settings['help']) }}</span>
                            <x-ui.field-error :name="$field" />
                        </label>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>

    <div class="flex flex-wrap gap-2">
        <x-ui.button type="submit"><x-ui.icon name="save" class="h-4 w-4" />{{ __('app.save') }}</x-ui.button>
        <x-ui.button :href="route('dashboard.accounts.festivals.workflows.edit', [$account, $edition, $workflow])" variant="secondary">{{ __('app.cancel') }}</x-ui.button>
    </div>
</form>

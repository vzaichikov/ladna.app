@extends('layouts.app')

@section('title', __('app.customer_notifications_queue').' - '.__('app.platform'))

@section('content')
    @php
        $notificationTimezone = fn ($notification): string => \App\Support\DateTimePresenter::safeTimezone(
            $notification->scheduledClass?->location?->timezone ?: $notification->account?->timezone,
        );
        $formatDate = fn ($notification, $date): string => \App\Support\DateTimePresenter::formatInTimezone($date, $notificationTimezone($notification), 'd.m.Y H:i')
            ?? __('app.not_set');
        $statusClass = fn ($status): string => match ($status?->value ?? $status) {
            \App\Enums\CustomerNotificationStatus::Sent->value => 'crm-status-active',
            \App\Enums\CustomerNotificationStatus::Failed->value => 'crm-status-danger',
            \App\Enums\CustomerNotificationStatus::Cancelled->value, \App\Enums\CustomerNotificationStatus::Skipped->value => 'crm-status-muted',
            default => 'crm-status-scheduled',
        };
        $hasFilters = $search !== '' || $status !== '' || $type !== '' || $channel !== '';
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.customer_notifications_queue') }}</h1>
            <p class="crm-page-copy">{{ __('app.customer_notifications_queue_copy') }}</p>
        </div>
    </div>

    <form method="GET" action="{{ route('platform.customer-notifications.index') }}" class="mt-6 grid gap-4 rounded-xl border border-stone-200 bg-white p-5 shadow-crm lg:grid-cols-[1fr_180px_180px_160px_auto] lg:items-end">
        <label class="block">
            <span class="crm-label">{{ __('app.search') }}</span>
            <input name="search" value="{{ $search }}" class="crm-field" placeholder="{{ __('app.customer_notifications_search_placeholder') }}">
        </label>

        <label class="block">
            <span class="crm-label">{{ __('app.status') }}</span>
            <select name="status" class="crm-field">
                <option value="">{{ __('app.all_statuses') }}</option>
                @foreach ($statuses as $notificationStatus)
                    <option value="{{ $notificationStatus->value }}" @selected($status === $notificationStatus->value)>
                        {{ __('app.customer_notification_status_'.$notificationStatus->value) }}
                    </option>
                @endforeach
            </select>
        </label>

        <label class="block">
            <span class="crm-label">{{ __('app.customer_notification_type') }}</span>
            <select name="type" class="crm-field">
                <option value="">{{ __('app.all_types') }}</option>
                @foreach ($types as $notificationType)
                    <option value="{{ $notificationType->value }}" @selected($type === $notificationType->value)>
                        {{ __($notificationType->labelKey()) }}
                    </option>
                @endforeach
            </select>
        </label>

        <label class="block">
            <span class="crm-label">{{ __('app.channel') }}</span>
            <select name="channel" class="crm-field">
                <option value="">{{ __('app.all_channels') }}</option>
                @foreach ($channels as $notificationChannel)
                    <option value="{{ $notificationChannel->value }}" @selected($channel === $notificationChannel->value)>
                        {{ __('app.customer_notification_channel_'.$notificationChannel->value) }}
                    </option>
                @endforeach
            </select>
        </label>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" variant="secondary">
                <x-ui.icon name="search" class="h-4 w-4" />
                {{ __('app.apply_filters') }}
            </x-ui.button>
            @if ($hasFilters)
                <x-ui.button :href="route('platform.customer-notifications.index')" variant="ghost">
                    {{ __('app.reset_filters') }}
                </x-ui.button>
            @endif
        </div>
    </form>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @if ($notifications->isEmpty())
            <x-ui.empty-state :title="__('app.customer_notifications_queue_empty')" icon="bell" class="m-5" />
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1180px] text-left text-sm">
                    <thead class="bg-stone-50 text-xs font-semibold uppercase text-slate-500">
                        <tr>
                            <th class="px-5 py-3">{{ __('app.account') }}</th>
                            <th class="px-5 py-3">{{ __('app.recipient') }}</th>
                            <th class="px-5 py-3">{{ __('app.customer_notification_type') }}</th>
                            <th class="px-5 py-3">{{ __('app.message') }}</th>
                            <th class="px-5 py-3">{{ __('app.status') }}</th>
                            <th class="px-5 py-3">{{ __('app.scheduled_at') }}</th>
                            <th class="px-5 py-3">{{ __('app.delivery') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($notifications as $notification)
                            @php
                                $scheduledClass = $notification->scheduledClass;
                            @endphp
                            <tr class="align-top">
                                <td class="px-5 py-4">
                                    <div class="font-semibold text-slate-950">{{ $notification->account?->name ?? __('app.not_set') }}</div>
                                    <div class="mt-1 text-sm text-slate-500">{{ $notification->account?->slug ?? __('app.not_set') }}</div>
                                    <div class="mt-1 text-xs text-slate-500">#{{ $notification->account_id }}</div>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="font-semibold text-slate-950">{{ $notification->recipient_name ?: ($notification->customer?->name ?? __('app.not_set')) }}</div>
                                    <div class="mt-1 font-mono text-sm text-slate-700">{{ $notification->recipient_phone ?: ($notification->customer?->phone ?? __('app.not_set')) }}</div>
                                    @if ($notification->customer_id)
                                        <div class="mt-1 text-xs text-slate-500">{{ __('app.customer') }} #{{ $notification->customer_id }}</div>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <div class="font-semibold text-slate-950">{{ __($notification->type->labelKey()) }}</div>
                                    <div class="mt-1 text-sm text-slate-500">{{ __('app.customer_notification_channel_'.$notification->channel->value) }}</div>
                                    @if ($scheduledClass)
                                        <div class="mt-2 text-xs text-slate-500">
                                            {{ $scheduledClass->title }} · {{ $formatDate($notification, $scheduledClass->starts_at) }}
                                        </div>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <div class="max-w-xl whitespace-pre-line leading-6 text-slate-700">{{ $notification->text ?: __('app.not_set') }}</div>
                                    @if ($notification->last_error)
                                        <div class="mt-2 rounded-lg bg-rose-50 px-3 py-2 text-xs leading-5 text-rose-800">{{ $notification->last_error }}</div>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <span class="{{ $statusClass($notification->status) }}">{{ __('app.customer_notification_status_'.$notification->status->value) }}</span>
                                    <div class="mt-2 text-xs text-slate-500">{{ __('app.attempts') }}: {{ $notification->attempts }}</div>
                                </td>
                                <td class="px-5 py-4 text-slate-700">
                                    <div>{{ __('app.scheduled_at') }}: {{ $formatDate($notification, $notification->scheduled_send_at) }}</div>
                                    <div class="mt-1 text-xs text-slate-500">{{ __('app.next_attempt_at') }}: {{ $formatDate($notification, $notification->next_attempt_at) }}</div>
                                    <div class="mt-1 text-xs text-slate-500">{{ __('app.created_at') }}: {{ $formatDate($notification, $notification->created_at) }}</div>
                                    <div class="mt-1 text-xs text-slate-500">{{ $notificationTimezone($notification) }}</div>
                                </td>
                                <td class="px-5 py-4 text-slate-700">
                                    <div>{{ __('app.provider') }}: {{ $notification->provider ?: __('app.not_set') }}</div>
                                    <div class="mt-1 text-xs text-slate-500">{{ __('app.provider_scope') }}: {{ $notification->provider_scope ?: __('app.not_set') }}</div>
                                    <div class="mt-1 text-xs text-slate-500">{{ __('app.sent_at') }}: {{ $formatDate($notification, $notification->sent_at) }}</div>
                                    @if ($notification->provider_message_id)
                                        <div class="mt-1 font-mono text-xs text-slate-500">{{ $notification->provider_message_id }}</div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($notifications->hasPages())
            <div class="border-t border-stone-100 px-5 py-4">
                {{ $notifications->links() }}
            </div>
        @endif
    </x-ui.panel>
@endsection

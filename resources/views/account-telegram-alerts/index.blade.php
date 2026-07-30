@extends('layouts.app')

@section('title', __('app.trainer_telegram_alert_log').' - '.$account->name)

@section('content')
    @php
        $formatDate = fn ($date): string => \App\Support\DateTimePresenter::formatInTimezone(
            $date,
            \App\Support\DateTimePresenter::safeTimezone($account->timezone),
            'd.m.Y H:i',
        ) ?? __('app.not_set');
        $statusClass = fn ($alertStatus): string => match ($alertStatus?->value ?? $alertStatus) {
            \App\Enums\TelegramAlertStatus::Sent->value => 'crm-status-active',
            \App\Enums\TelegramAlertStatus::Failed->value => 'crm-status-danger',
            \App\Enums\TelegramAlertStatus::Pending->value, \App\Enums\TelegramAlertStatus::Processing->value => 'crm-status-scheduled',
            default => 'crm-status-muted',
        };
        $hasFilters = $search !== '' || $status !== '' || $type !== '';
    @endphp

    <div>
        <h1 class="crm-page-title">{{ __('app.trainer_telegram_alert_log') }}</h1>
        <p class="crm-page-copy">{{ __('app.trainer_telegram_alert_log_copy') }}</p>
    </div>

    <form method="GET" action="{{ route('dashboard.accounts.trainer-telegram-alert-logs.index', $account) }}" class="mt-6 grid gap-4 rounded-xl border border-stone-200 bg-white p-5 shadow-crm lg:grid-cols-[1fr_180px_220px_auto] lg:items-end">
        <label class="block">
            <span class="crm-label">{{ __('app.search') }}</span>
            <input name="search" value="{{ $search }}" class="crm-field" placeholder="{{ __('app.trainer_telegram_alert_log_search_placeholder') }}">
        </label>

        <label class="block">
            <span class="crm-label">{{ __('app.status') }}</span>
            <select name="status" class="crm-field">
                <option value="">{{ __('app.all_statuses') }}</option>
                @foreach ($statuses as $alertStatus)
                    <option value="{{ $alertStatus->value }}" @selected($status === $alertStatus->value)>
                        {{ __('app.telegram_alert_status_'.$alertStatus->value) }}
                    </option>
                @endforeach
            </select>
        </label>

        <label class="block">
            <span class="crm-label">{{ __('app.telegram_alert_type') }}</span>
            <select name="type" class="crm-field">
                <option value="">{{ __('app.all_alert_types') }}</option>
                @foreach ($types as $alertType)
                    <option value="{{ $alertType->value }}" @selected($type === $alertType->value)>
                        {{ __($alertType->labelKey()) }}
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
                <x-ui.button :href="route('dashboard.accounts.trainer-telegram-alert-logs.index', $account)" variant="ghost">
                    {{ __('app.reset_filters') }}
                </x-ui.button>
            @endif
        </div>
    </form>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        <div class="border-b border-stone-100 p-5">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.telegram_alert_logs') }}</h2>
        </div>

        @forelse ($alerts as $alert)
            @php
                $rowType = $alert->type?->value ?? $alert->type;
                $rowStatus = $alert->status?->value ?? $alert->status;
                $classLabel = data_get($alert->payload, 'class_name')
                    ?? $alert->scheduledClass?->title
                    ?? __('app.not_set');
                $classTime = data_get($alert->payload, 'class_time') ?? __('app.not_set');
                $locationLabel = data_get($alert->payload, 'location_name')
                    ?? $alert->scheduledClass?->location?->name
                    ?? __('app.not_set');
                $roomLabel = data_get($alert->payload, 'room_name')
                    ?? $alert->scheduledClass?->room?->name
                    ?? __('app.not_set');
            @endphp
            <article class="crm-row lg:grid-cols-[150px_minmax(0,1fr)_minmax(0,2fr)_minmax(0,1.4fr)_160px] lg:items-start">
                <div>
                    <span class="{{ $statusClass($alert->status) }}">{{ __('app.telegram_alert_status_'.$rowStatus) }}</span>
                    <div class="mt-2 text-xs font-medium text-slate-500">{{ __('app.telegram_alert_type_'.$rowType) }}</div>
                    <div class="mt-1 text-xs text-slate-500">{{ __('app.attempts') }}: {{ $alert->attempts }}</div>
                </div>
                <div class="min-w-0">
                    <div class="font-semibold text-slate-950">{{ $account->name }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $account->slug }}</div>
                    <div class="mt-2 text-xs text-slate-500">{{ $alert->installation?->bot_username ?? __('app.telegram_bot_profile_owner') }}</div>
                </div>
                <div class="min-w-0 text-sm leading-6 text-slate-700">
                    <div class="font-semibold text-slate-950">{{ $classLabel }}</div>
                    <div>{{ $classTime }}</div>
                    <div class="text-slate-500">{{ $locationLabel }} · {{ $roomLabel }}</div>
                    <div class="mt-1">{{ \Illuminate\Support\Str::limit((string) $alert->text, 220) }}</div>
                </div>
                <div class="min-w-0 text-sm leading-6 text-slate-700">
                    <div class="font-semibold text-slate-950">{{ $alert->trainer?->name ?? __('app.trainer_not_assigned') }}</div>
                    <div class="text-slate-500">{{ __('app.telegram_chat') }}: {{ $alert->telegram_chat_id ?? __('app.not_set') }}</div>
                    @if ($alert->last_error)
                        <div class="mt-1 font-medium text-rose-700">{{ \Illuminate\Support\Str::limit($alert->last_error, 180) }}</div>
                    @endif
                </div>
                <div class="text-sm text-slate-500">
                    {{ $formatDate($alert->sent_at ?? $alert->failed_at ?? $alert->created_at) }}
                    @if ($alert->next_attempt_at)
                        <div class="mt-1 text-xs text-slate-500">{{ __('app.next_attempt_at') }}: {{ $formatDate($alert->next_attempt_at) }}</div>
                    @endif
                </div>
            </article>
        @empty
            <x-ui.empty-state :title="__('app.telegram_no_alert_logs')" icon="telegram" class="m-5" />
        @endforelse

        @if ($alerts->hasPages())
            <div class="border-t border-stone-100 px-5 py-4">
                {{ $alerts->links() }}
            </div>
        @endif
    </x-ui.panel>
@endsection

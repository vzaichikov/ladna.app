@extends('layouts.app')

@section('title', __('app.account_activity_log').' - '.$account->name)

@section('content')
    <div>
        <h1 class="crm-page-title">{{ __('app.account_activity_log') }}</h1>
        <p class="crm-page-copy">{{ __('app.account_activity_log_copy', ['days' => $retentionDays]) }}</p>
    </div>

    <form method="GET" action="{{ route('dashboard.accounts.activity-logs.index', $account) }}" class="mt-6 rounded-xl border border-stone-200 bg-white p-4 shadow-xs">
        <div class="grid gap-3 lg:grid-cols-[1fr_220px_160px_160px_auto_auto] lg:items-end">
            <label class="block">
                <span class="crm-label">{{ __('app.activity_log_actor') }}</span>
                <input name="actor" value="{{ $actor }}" class="crm-field" placeholder="{{ __('app.activity_log_actor_placeholder') }}">
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.action') }}</span>
                <select name="action" class="crm-field">
                    <option value="">{{ __('app.all_actions') }}</option>
                    @foreach ($actions as $availableAction)
                        <option value="{{ $availableAction }}" @selected($action === $availableAction)>{{ $availableAction }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.date_from') }}</span>
                <input name="date_from" type="date" value="{{ $dateFrom }}" class="crm-field">
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.date_to') }}</span>
                <input name="date_to" type="date" value="{{ $dateTo }}" class="crm-field">
            </label>
            <x-ui.button type="submit">{{ __('app.apply_filters') }}</x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.activity-logs.index', $account)" variant="secondary">{{ __('app.reset_filters') }}</x-ui.button>
        </div>
    </form>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @forelse ($activityLogs as $activityLog)
            <div class="crm-row xl:grid-cols-[190px_1fr_1fr_1fr_auto] xl:items-center">
                <div class="text-sm font-medium text-slate-500">
                    {{ $activityLog->occurred_at?->format('Y-m-d H:i') }}
                </div>
                <div>
                    <div class="font-semibold text-slate-950">{{ $activityLog->action }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $activityLog->method }} · {{ $activityLog->status_code }}</div>
                </div>
                <div>
                    <div class="font-semibold text-slate-950">{{ $activityLog->actor_name ?? __('app.system') }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $activityLog->actor_email ?? $activityLog->actor_role ?? __('app.not_set') }}</div>
                </div>
                <div>
                    <div class="font-semibold text-slate-950">{{ $activityLog->subject_label ?? __('app.not_set') }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $activityLog->subject_type ? class_basename($activityLog->subject_type) : __('app.not_set') }} #{{ $activityLog->subject_id }}</div>
                </div>
                <div class="text-sm text-slate-500 xl:text-right">
                    <div>{{ $activityLog->ip_address ?? __('app.not_set') }}</div>
                    <div class="mt-1 max-w-64 truncate">{{ $activityLog->url }}</div>
                </div>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.no_account_activity_logs')" icon="activity-log" class="m-5" />
        @endforelse
    </x-ui.panel>

    <div class="mt-6">
        {{ $activityLogs->links() }}
    </div>
@endsection

@extends('layouts.app')

@section('title', __('app.sms_delivery_log').' - '.$account->name)

@section('content')
    @php
        $timezone = $account->timezone ?: config('app.timezone');
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="crm-page-kicker">{{ $account->name }}</div>
            <h1 class="crm-page-title">{{ __('app.sms_delivery_log') }}</h1>
            <p class="crm-page-copy">{{ __('app.sms_delivery_log_copy') }}</p>
        </div>
        <x-ui.button :href="route('dashboard.accounts.sms-account.show', $account)" variant="secondary">{{ __('app.sms_account') }}</x-ui.button>
    </div>

    <x-ui.panel padding="lg" class="mt-6">
        <form method="GET" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <label class="block">
                <span class="crm-label">{{ __('app.search') }}</span>
                <input name="search" class="crm-field" value="{{ $filters['search'] }}">
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.sms_purpose') }}</span>
                <select name="purpose" class="crm-field">
                    <option value="">{{ __('app.all') }}</option>
                    @foreach ($purposes as $purpose)
                        <option value="{{ $purpose->value }}" @selected($filters['purpose'] === $purpose->value)>{{ __('app.sms_purpose_'.$purpose->value) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.status') }}</span>
                <select name="status" class="crm-field">
                    <option value="">{{ __('app.all') }}</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ __('app.sms_delivery_status_'.$status->value) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.sms_source') }}</span>
                <select name="mode" class="crm-field">
                    <option value="">{{ __('app.all') }}</option>
                    @foreach ($modes as $mode)
                        <option value="{{ $mode->value }}" @selected($filters['mode'] === $mode->value)>{{ __('app.sms_mode_'.$mode->value) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.provider') }}</span>
                <select name="provider" class="crm-field">
                    <option value="">{{ __('app.all') }}</option>
                    @foreach ($providers as $provider)
                        <option value="{{ $provider }}" @selected($filters['provider'] === $provider)>{{ $provider }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.date_from') }}</span>
                <input name="date_from" type="date" class="crm-field" value="{{ $filters['dateFrom'] }}">
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.date_to') }}</span>
                <input name="date_to" type="date" class="crm-field" value="{{ $filters['dateTo'] }}">
            </label>
            <div class="flex items-end gap-3">
                <x-ui.button type="submit">{{ __('app.filter') }}</x-ui.button>
                <x-ui.button :href="route('dashboard.accounts.customer-notification-logs.index', $account)" variant="secondary">{{ __('app.reset') }}</x-ui.button>
            </div>
        </form>
    </x-ui.panel>

    <x-sms-delivery-table :deliveries="$deliveries" :timezone="$timezone" />

    @if ($deliveries->hasPages())
        <div class="mt-6">{{ $deliveries->links() }}</div>
    @endif
@endsection

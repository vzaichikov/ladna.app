@extends('layouts.app')

@section('title', __('app.sms_delivery_log').' - '.$account->name)

@section('content')
    @php
        $timezone = $account->timezone ?: config('app.timezone');
        $formatMoney = fn (?int $cents): string => \App\Support\MoneyFormatter::format($cents, 'UAH');
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

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @forelse ($deliveries as $delivery)
            <div class="crm-row lg:grid-cols-[minmax(0,1.5fr)_140px_130px_120px_120px_170px] lg:items-center">
                <div>
                    <div class="font-semibold text-slate-950">{{ __('app.sms_purpose_'.$delivery->purpose->value) }} · {{ $delivery->recipient_phone }}</div>
                    <div class="mt-1 text-sm text-slate-500">
                        {{ __('app.sms_mode_'.$delivery->source_mode->value) }} · {{ $delivery->provider ?: '—' }} · {{ $delivery->provider_message_id ?: '—' }}
                    </div>
                    @if ($delivery->message_preview)
                        <div class="mt-1 text-sm text-slate-600">{{ $delivery->message_preview }}</div>
                    @endif
                    @if ($delivery->last_error)
                        <div class="mt-1 text-xs text-rose-700">{{ $delivery->last_error }}</div>
                    @endif
                </div>
                <span class="{{ $delivery->status === \App\Enums\SmsDeliveryStatus::Delivered ? 'crm-status-active' : 'crm-status-muted' }}">{{ __('app.sms_delivery_status_'.$delivery->status->value) }}</span>
                <div class="text-sm text-slate-600">{{ $delivery->billed_segments ?? $delivery->estimated_segments }} {{ __('app.sms_segments_short') }}</div>
                <div class="text-sm text-slate-600">{{ $delivery->sms_segment_price_cents === null ? '—' : $formatMoney($delivery->sms_segment_price_cents) }}</div>
                <div class="font-semibold text-slate-700">{{ $delivery->amount_cents === null ? '—' : $formatMoney($delivery->amount_cents) }}</div>
                <div class="text-sm text-slate-500">{{ $delivery->created_at?->timezone($timezone)->format('d.m.Y H:i') }}</div>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.sms_no_deliveries')" icon="bell" class="m-5" />
        @endforelse
    </x-ui.panel>

    @if ($deliveries->hasPages())
        <div class="mt-6">{{ $deliveries->links() }}</div>
    @endif
@endsection

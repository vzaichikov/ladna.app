@extends('layouts.app')

@section('title', __('app.accounts').' - '.__('app.platform'))

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="crm-page-kicker">{{ __('app.platform') }}</div>
            <h1 class="crm-page-title">{{ __('app.accounts') }}</h1>
        </div>
        <x-ui.button :href="route('platform.accounts.create')">
            <x-ui.icon name="plus" class="h-4 w-4" />
            {{ __('app.create_account') }}
        </x-ui.button>
    </div>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @forelse ($accounts as $account)
            @php
                $statusClass = match ($account->status->value) {
                    'active' => 'crm-status-active',
                    'trialing' => 'crm-status-scheduled',
                    default => 'crm-status-muted',
                };
            @endphp
            @php
                $readiness = $customerAuthReadiness->get($account->getKey(), []);
                $otpReady = (bool) ($readiness['otp'] ?? false);
                $otpEnabled = (bool) ($readiness['otp_enabled'] ?? false);
            @endphp
            <div class="crm-row transition hover:bg-violet-crm-50/50 lg:grid-cols-[1.3fr_1fr_1fr_auto_auto] lg:items-center">
                <div>
                    <a href="{{ route('platform.accounts.show', $account) }}" class="font-semibold text-slate-950 transition hover:text-brand-700">{{ $account->name }}</a>
                    <div class="mt-1 text-sm text-slate-500">{{ $account->slug }}</div>
                </div>
                <div class="text-sm text-slate-500">{{ $account->locations_count }} {{ __('app.locations') }}</div>
                <div class="text-sm text-slate-500">{{ $account->subscription?->plan?->name ?? '-' }}</div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="{{ $statusClass }}">{{ __('app.'.$account->status->value) }}</span>
                    <span class="{{ $otpReady ? 'crm-status-active' : ($otpEnabled ? 'crm-status-scheduled' : 'crm-status-muted') }}">
                        {{ $otpReady ? __('app.otp_ready') : ($otpEnabled ? __('app.otp_needs_setup') : __('app.otp_off')) }}
                    </span>
                </div>
                <x-ui.button :href="route('platform.accounts.customer-auth.edit', $account)" variant="secondary" size="sm">
                    <x-ui.icon name="key-round" class="h-4 w-4" />
                    {{ __('app.customer_otp_tariff_short') }}
                </x-ui.button>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.no_accounts')" icon="accounts" class="m-5" />
        @endforelse
    </x-ui.panel>
@endsection

@extends('layouts.app')

@section('title', __('app.platform').' - '.__('app.app_name'))

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.platform') }}</h1>
            <p class="crm-page-copy">{{ __('app.platform_admin') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-ui.button :href="route('platform.integrations.index')" variant="secondary">{{ __('app.integrations') }}</x-ui.button>
            <x-ui.button :href="route('platform.subscription-plans.index')" variant="secondary">{{ __('app.subscription_plans') }}</x-ui.button>
            <x-ui.button :href="route('platform.accounts.index')">{{ __('app.accounts') }}</x-ui.button>
        </div>
    </div>

    <section class="mt-8 grid gap-4 md:grid-cols-2">
        <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
            <div class="text-sm text-slate-500">{{ __('app.accounts') }}</div>
            <div class="mt-2 text-3xl font-semibold">{{ $accountsCount }}</div>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
            <div class="text-sm text-slate-500">{{ __('app.active') }}</div>
            <div class="mt-2 text-3xl font-semibold">{{ $activeAccountsCount }}</div>
        </div>
    </section>

    <section class="mt-8 overflow-hidden rounded-xl border border-stone-200 bg-white shadow-crm">
        @foreach ($recentAccounts as $account)
            @php
                $statusClass = match ($account->status->value) {
                    'active' => 'crm-status-active',
                    'trialing' => 'crm-status-scheduled',
                    default => 'crm-status-muted',
                };
            @endphp
            <a href="{{ route('platform.accounts.show', $account) }}" class="flex items-center justify-between gap-4 border-b border-stone-100 px-5 py-4 last:border-b-0">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="font-semibold">{{ $account->name }}</div>
                        @if ($account->isReadOnlyDemo())
                            <span class="crm-status-scheduled">{{ __('app.demo_account_badge') }}</span>
                        @endif
                    </div>
                    <div class="mt-1 text-sm text-slate-500">{{ $account->subscription?->plan?->name ?? __('app.subscription_plan') }}</div>
                </div>
                <span class="{{ $statusClass }}">{{ __('app.'.$account->status->value) }}</span>
            </a>
        @endforeach
    </section>
@endsection

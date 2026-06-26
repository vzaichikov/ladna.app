@extends('layouts.app')

@section('title', __('app.tariff_payments').' - '.$account->name)

@section('content')
    @php
        $plan = $subscription?->plan;
        $timezone = $account->timezone ?? config('app.timezone');
        $formatMoney = fn (?int $cents, ?string $currency): string => number_format(($cents ?? 0) / 100, 2).' '.($currency ?: $account->default_currency);
        $statusClass = match ($subscription?->status?->value) {
            'active' => 'crm-status-active',
            'suspended', 'cancelled' => 'crm-status-danger',
            default => 'crm-status-muted',
        };
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.tariff_payments') }}</h1>
            <p class="crm-page-copy">{{ __('app.tariff_payments_copy') }}</p>
        </div>
    </div>

    <section class="mt-6 grid gap-4 lg:grid-cols-3">
        <x-ui.metric
            :label="__('app.subscription_plan')"
            :value="$plan?->name ?? __('app.not_set')"
            :meta="$plan?->billing_interval ? __('app.'.$plan->billing_interval) : null"
            icon="payments"
            accent="emerald"
        />
        <x-ui.metric
            :label="__('app.subscription_status')"
            :value="$subscription?->status ? __('app.'.$subscription->status->value) : __('app.not_set')"
            icon="bell"
            accent="slate"
        />
        <x-ui.metric
            :label="__('app.subscription_price')"
            :value="$plan ? $formatMoney($plan->price_cents, $plan->currency) : __('app.not_set')"
            :meta="$plan?->billing_interval ? __('app.'.$plan->billing_interval) : null"
            icon="class-pass-plans"
            accent="brand"
        />
    </section>

    <x-ui.panel class="mt-6 max-w-4xl">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.current_subscription') }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.tariff_payments_mock_notice') }}</p>
            </div>
            <span class="{{ $statusClass }}">{{ $subscription?->status ? __('app.'.$subscription->status->value) : __('app.not_set') }}</span>
        </div>

        <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2">
            <div class="rounded-lg bg-slate-50 p-4">
                <dt class="text-slate-500">{{ __('app.subscription_plan') }}</dt>
                <dd class="mt-1 font-semibold text-slate-950">{{ $plan?->name ?? __('app.not_set') }}</dd>
            </div>
            <div class="rounded-lg bg-slate-50 p-4">
                <dt class="text-slate-500">{{ __('app.billing_interval') }}</dt>
                <dd class="mt-1 font-semibold text-slate-950">{{ $plan?->billing_interval ? __('app.'.$plan->billing_interval) : __('app.not_set') }}</dd>
            </div>
            <div class="rounded-lg bg-slate-50 p-4">
                <dt class="text-slate-500">{{ __('app.started_at') }}</dt>
                <dd class="mt-1 font-semibold text-slate-950">{{ $subscription?->started_at?->timezone($timezone)->format('Y-m-d') ?? __('app.not_set') }}</dd>
            </div>
            <div class="rounded-lg bg-slate-50 p-4">
                <dt class="text-slate-500">{{ __('app.end_date') }}</dt>
                <dd class="mt-1 font-semibold text-slate-950">{{ $subscription?->ends_at?->timezone($timezone)->format('Y-m-d') ?? __('app.not_set') }}</dd>
            </div>
        </dl>
    </x-ui.panel>
@endsection

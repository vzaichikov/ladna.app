@extends('layouts.app')

@section('title', __('app.tariff_payments').' - '.$account->name)

@section('content')
    @php
        $plan = $subscription?->plan;
        $timezone = $account->timezone ?? config('app.timezone');
        $formatMoney = fn (?int $cents, ?string $currency): string => number_format(($cents ?? 0) / 100, 2).' '.($currency ?: $account->default_currency);
        $statusClass = match ($subscription?->status?->value) {
            'active' => 'crm-status-active',
            'trialing' => 'crm-status-scheduled',
            'past_due' => 'crm-status-warning',
            'expired', 'suspended', 'cancelled' => 'crm-status-danger',
            default => 'crm-status-muted',
        };
        $isPromo = $plan?->plan_type === \App\Enums\SubscriptionPlanType::Promo;
        $paymentTargetPlan = $plan?->plan_type === \App\Enums\SubscriptionPlanType::Standard ? $plan : $standardPlan;
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.tariff_payments') }}</h1>
            <p class="crm-page-copy">{{ __('app.tariff_payments_copy') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if (! $isPromo)
                <form method="POST" action="{{ route('dashboard.accounts.tariff-payments.pay-now', $account) }}">
                    @csrf
                    <x-ui.button type="submit">
                        <x-ui.icon name="payments" class="h-4 w-4" />
                        {{ $paymentTargetPlan->requires_recurring_payment ? __('app.subscribe_or_pay_now') : __('app.pay_now') }}
                    </x-ui.button>
                </form>
            @endif
            @if ($supportUrl)
                <x-ui.button :href="$supportUrl" variant="secondary">
                    {{ __('app.support') }}
                </x-ui.button>
            @endif
        </div>
    </div>

    <section class="mt-6 grid gap-4 lg:grid-cols-4">
        <x-ui.metric
            :label="__('app.subscription_plan')"
            :value="$plan?->name ?? __('app.not_set')"
            :meta="$plan?->plan_type ? __('app.subscription_plan_type_'.$plan->plan_type->value) : null"
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
        <x-ui.metric
            :label="__('app.next_payment_at')"
            :value="$subscription?->next_payment_at?->timezone($timezone)->format('Y-m-d') ?? __('app.not_set')"
            :meta="$subscription?->auto_renew_enabled ? __('app.auto_renew_enabled') : __('app.auto_renew_disabled')"
            icon="schedule"
            accent="amber"
        />
    </section>

    <x-ui.panel class="mt-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.current_subscription') }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">
                    {{ $isPromo ? __('app.subscription_promo_copy') : __('app.subscription_payment_copy') }}
                </p>
            </div>
            <span class="{{ $statusClass }}">{{ $subscription?->status ? __('app.'.$subscription->status->value) : __('app.not_set') }}</span>
        </div>

        <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg bg-slate-50 p-4">
                <dt class="text-slate-500">{{ __('app.started_at') }}</dt>
                <dd class="mt-1 font-semibold text-slate-950">{{ $subscription?->started_at?->timezone($timezone)->format('Y-m-d') ?? __('app.not_set') }}</dd>
            </div>
            <div class="rounded-lg bg-slate-50 p-4">
                <dt class="text-slate-500">{{ __('app.end_date') }}</dt>
                <dd class="mt-1 font-semibold text-slate-950">{{ $subscription?->ends_at?->timezone($timezone)->format('Y-m-d') ?? __('app.not_set') }}</dd>
            </div>
            <div class="rounded-lg bg-slate-50 p-4">
                <dt class="text-slate-500">{{ __('app.payment_provider') }}</dt>
                <dd class="mt-1 font-semibold text-slate-950">{{ $subscription?->payment_provider ? __('app.'.$subscription->payment_provider) : __('app.not_set') }}</dd>
            </div>
            <div class="rounded-lg bg-slate-50 p-4">
                <dt class="text-slate-500">{{ __('app.provider_status') }}</dt>
                <dd class="mt-1 font-semibold text-slate-950">{{ $subscription?->provider_status ?? __('app.not_set') }}</dd>
            </div>
        </dl>
    </x-ui.panel>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        <div class="border-b border-stone-100 p-5">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.payment_history') }}</h2>
        </div>

        @forelse ($payments as $payment)
            @php
                $paymentStatusClass = match ($payment->status->value) {
                    'payment_paid' => 'crm-status-active',
                    'payment_pending', 'payment_started' => 'crm-status-scheduled',
                    'payment_failed', 'payment_cancelled', 'payment_expired' => 'crm-status-danger',
                    default => 'crm-status-muted',
                };
            @endphp
            <div class="crm-row lg:grid-cols-[1fr_140px_140px_150px_auto] lg:items-center">
                <div>
                    <div class="font-semibold text-slate-950">{{ $payment->plan?->name ?? __('app.not_set') }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $payment->order_id }}</div>
                </div>
                <div class="text-sm font-semibold text-slate-700">{{ $formatMoney($payment->amount_cents, $payment->currency) }}</div>
                <div class="text-sm text-slate-500">{{ __('app.'.$payment->payment_type->value) }}</div>
                <div class="text-sm text-slate-500">
                    {{ $payment->paid_at?->timezone($timezone)->format('Y-m-d H:i') ?? $payment->started_at?->timezone($timezone)->format('Y-m-d H:i') ?? __('app.not_set') }}
                </div>
                <span class="{{ $paymentStatusClass }}">{{ __('app.'.$payment->status->value) }}</span>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.no_subscription_payments')" icon="payments" class="m-5" />
        @endforelse
    </x-ui.panel>

    @if ($payments->hasPages())
        <div class="mt-6">
            {{ $payments->links() }}
        </div>
    @endif
@endsection

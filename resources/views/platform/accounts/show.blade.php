@extends('layouts.app')

@section('title', $account->name.' - '.__('app.platform'))

@section('content')
    @php
        $formatMoney = fn (?int $cents, ?string $currency): string => \App\Support\MoneyFormatter::format($cents, $currency ?: $account->default_currency);
        $timezone = $account->timezone ?? config('app.timezone');
    @endphp

    <x-ui.panel padding="lg">
        <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-center gap-4">
                <span class="flex h-16 w-16 items-center justify-center rounded-xl bg-brand-50">
                    <img src="{{ $account->logoUrl() }}" alt="" class="max-h-11 max-w-11 object-contain">
                </span>
                <div>
                    <div class="crm-page-kicker">{{ __('app.platform') }}</div>
                    <h1 class="crm-page-title">{{ $account->name }}</h1>
                    <p class="crm-page-copy">{{ $account->slug }} · {{ __('app.'.$account->status->value) }}</p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button :href="route('platform.accounts.edit', $account)">
                    <x-ui.icon name="edit" class="h-4 w-4" />
                    {{ __('app.edit') }}
                </x-ui.button>
                <x-ui.button :href="route('platform.accounts.customer-auth.edit', $account)" variant="secondary">
                    <x-ui.icon name="key-round" class="h-4 w-4" />
                    {{ __('app.customer_otp_tariff_settings') }}
                </x-ui.button>
                <form method="POST" action="{{ route('platform.accounts.destroy', $account) }}" data-confirm-delete>
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="danger">
                        <x-ui.icon name="trash" class="h-4 w-4" />
                        {{ __('app.delete') }}
                    </x-ui.button>
                </form>
            </div>
        </div>
    </x-ui.panel>

    <section class="mt-6 grid gap-4 md:grid-cols-3">
        <x-ui.metric :label="__('app.locations')" :value="$account->locations->count()" icon="locations" />
        <x-ui.metric :label="__('app.generated_classes')" :value="$account->scheduled_classes_count" icon="generated-classes" accent="brand" />
        <x-ui.metric :label="__('app.subscription_plan')" :value="$account->subscription?->plan?->name ?? '-'" icon="platform" accent="emerald" />
    </section>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        <div class="border-b border-stone-100 p-5">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.payment_history') }}</h2>
        </div>

        @forelse ($subscriptionPayments as $payment)
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
@endsection

@extends('layouts.app')

@section('title', __('app.payments').' - '.__('app.platform'))

@section('content')
    @php
        $formatMoney = fn (?int $cents, ?string $currency = 'UAH'): string => \App\Support\MoneyFormatter::format($cents ?? 0, $currency ?? 'UAH');
        $formatPaymentDate = fn ($payment): string => \App\Support\DateTimePresenter::format($payment->paid_at ?? $payment->started_at, $payment->account) ?? __('app.not_set');
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.payments') }}</h1>
            <p class="crm-page-copy">{{ __('app.platform_payments_copy') }}</p>
        </div>
    </div>

    <section class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
        <x-ui.metric :label="__('app.payments_total')" :value="$stats['total']" icon="payments" accent="slate" />
        <x-ui.metric :label="__('app.payment_paid')" :value="$formatMoney($stats['paid_amount_cents'])" icon="check-circle" accent="emerald" />
        <x-ui.metric :label="__('app.payment_pending')" :value="$stats['pending']" icon="schedule" accent="brand" />
        <x-ui.metric :label="__('app.payment_failed')" :value="$stats['failed']" icon="bell" accent="violet" />
        @if ($fiscalizationEnabled)
            <x-ui.metric :label="__('app.fiscalization_failed')" :value="$stats['fiscal_failed']" icon="settings" accent="slate" />
        @endif
    </section>

    <form method="GET" action="{{ route('platform.payments.index') }}" class="mt-6 grid gap-4 rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:grid-cols-[1fr_1fr_auto] sm:items-end">
        <label class="block">
            <span class="crm-label">{{ __('app.payment_status') }}</span>
            <select name="status" class="crm-field">
                <option value="">{{ __('app.all_statuses') }}</option>
                @foreach ($statuses as $statusOption)
                    <option value="{{ $statusOption->value }}" @selected($status === $statusOption->value)>{{ __('app.'.$statusOption->value) }}</option>
                @endforeach
            </select>
        </label>

        <label class="block">
            <span class="crm-label">{{ __('app.payment_provider') }}</span>
            <select name="provider" class="crm-field">
                <option value="">{{ __('app.all_payment_providers') }}</option>
                @foreach ($providers as $providerKey => $providerLabel)
                    <option value="{{ $providerKey }}" @selected($provider === $providerKey)>{{ $providerLabel }}</option>
                @endforeach
            </select>
        </label>

        <x-ui.button type="submit" variant="secondary">
            <x-ui.icon name="search" class="h-4 w-4" />
            {{ __('app.apply_filters') }}
        </x-ui.button>
    </form>

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
                $receipt = $payment->fiscalReceipt;
                $fiscalStatusClass = match ($receipt?->status?->value) {
                    'fiscalized' => 'crm-status-active',
                    'processing', 'pending' => 'crm-status-scheduled',
                    'failed' => 'crm-status-danger',
                    default => 'crm-status-muted',
                };
                $providerLabel = config('integrations.providers.'.$payment->provider.'.label', $payment->provider);
            @endphp

            <article class="crm-row lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_130px_140px_160px] lg:items-center">
                <div class="min-w-0">
                    <div class="font-semibold text-slate-950">{{ $payment->plan?->name ?? __('app.not_set') }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $payment->order_id }}</div>
                    <div class="mt-1 text-xs font-medium text-slate-500">{{ __('app.payment_type') }}: {{ __('app.'.$payment->payment_type->value) }}</div>
                </div>

                <div class="min-w-0 text-sm">
                    <div class="font-semibold text-slate-950">{{ $payment->account?->name ?? __('app.not_set') }}</div>
                    <div class="mt-1 text-slate-500">{{ $payment->account?->slug ?? __('app.not_set') }}</div>
                </div>

                <div class="text-sm font-semibold text-slate-700">{{ $formatMoney($payment->amount_cents, $payment->currency) }}</div>

                <div class="text-sm text-slate-500">
                    <div>{{ $providerLabel }}</div>
                    <div class="mt-1">{{ $formatPaymentDate($payment) }}</div>
                </div>

                <div class="space-y-2">
                    <span class="{{ $paymentStatusClass }}">{{ __('app.'.$payment->status->value) }}</span>

                    @if ($fiscalizationEnabled)
                        <div class="text-xs leading-5 text-slate-500">
                            <span class="{{ $fiscalStatusClass }}">{{ $receipt?->status ? __('app.fiscal_status_'.$receipt->status->value) : __('app.fiscal_status_pending') }}</span>
                            @if ($receipt?->fiscal_number)
                                <div class="mt-1 font-semibold text-slate-700">{{ __('app.fiscal_receipt_number') }}: {{ $receipt->fiscal_number }}</div>
                            @endif
                            @if ($receipt?->last_error)
                                <div class="mt-1 text-rose-700">{{ $receipt->last_error }}</div>
                                <div class="mt-1 text-rose-700">{{ __('app.fiscalization_contact_checkbox') }}</div>
                            @endif
                        </div>
                    @endif
                </div>
            </article>
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

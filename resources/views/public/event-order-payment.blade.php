@extends('layouts.public')

@section('title', __('app.event_monopay_payment_title').' - '.$order->event->title)
@section('publicFooter')
    <x-ui.powered-footer :account="$account" :show-locale-switcher="true" class="mx-auto max-w-4xl bg-canvas px-5 pb-8 sm:px-8" />
@endsection

@section('content')
<main class="min-h-[calc(100vh-8rem)] bg-canvas px-5 py-8 text-slate-950 sm:px-8">
    <section class="mx-auto max-w-4xl">
        <x-ui.public-studio-header :account="$account" class="mb-6" />

        <div class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-7">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-sm font-semibold text-brand-700">{{ $order->event->title }}</p>
                    <h1 class="mt-2 text-2xl font-semibold sm:text-3xl">{{ __('app.event_monopay_payment_title') }}</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.event_monopay_payment_help') }}</p>
                </div>
                <div class="shrink-0 rounded-xl bg-slate-50 px-4 py-3 text-left sm:text-right">
                    <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('app.total') }}</span>
                    <span class="mt-1 block text-lg font-semibold text-slate-950">{{ \App\Support\MoneyFormatter::format($order->amount_cents, $order->currency) }}</span>
                    @if ((int) ($order->discount_cents ?? 0) > 0)
                        <span class="mt-1 block text-xs text-slate-500">{{ __('app.subtotal') }}: {{ \App\Support\MoneyFormatter::format($order->subtotal_cents, $order->currency) }}</span>
                        <span class="mt-1 block text-xs font-semibold text-emerald-700">{{ $order->promo_code }}: −{{ \App\Support\MoneyFormatter::format($order->discount_cents, $order->currency) }}</span>
                    @endif
                </div>
            </div>

            <div
                class="mt-5"
                data-event-monopay-iframe
                data-iframe-origin="{{ $iframeOrigin }}"
                data-return-url="{{ $returnUrl }}"
                data-event-order-poll
                data-status-url="{{ $statusUrl }}"
                data-refresh-url="{{ $returnUrl }}"
                data-timeout-message="{{ __('app.event_order_confirmation_taking_longer') }}"
            >
                <div class="flex justify-center">
                    <iframe
                        id="payFrame"
                        title="{{ __('app.event_monopay_iframe_title') }}"
                        width="100%"
                        height="700"
                        src="{{ $pageUrl }}"
                        allow="payment *"
                        class="h-[700px] w-full max-w-[600px] rounded-3xl border-0 bg-white md:h-[600px]"
                        data-event-monopay-frame
                    ></iframe>
                </div>

                <div class="mt-4 flex flex-wrap justify-center gap-2">
                    <x-ui.button :href="$pageUrl" variant="secondary" target="_blank" rel="noopener noreferrer" data-event-monopay-direct-link>
                        <x-ui.icon name="external-link" class="h-4 w-4" />
                        {{ __('app.event_monopay_open_direct') }}
                    </x-ui.button>
                    <x-ui.button :href="$returnUrl" variant="secondary">
                        <x-ui.icon name="arrow-left" class="h-4 w-4" />
                        {{ __('app.event_monopay_back_to_order') }}
                    </x-ui.button>
                </div>

                <div class="mt-4 hidden rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950" data-event-order-poll-timeout>
                    <p data-event-order-poll-message aria-live="polite">{{ __('app.event_order_confirmation_taking_longer') }}</p>
                </div>
            </div>

            <x-ui.accepted-card-brands class="mt-5" />
        </div>
    </section>
</main>
@endsection

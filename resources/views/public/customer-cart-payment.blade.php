@extends('layouts.public')

@section('title', __('app.customer_cart_payment_title').' - '.$account->name)

@section('publicFooter')
    <x-ui.powered-footer :account="$account" :show-locale-switcher="true" class="mx-auto max-w-5xl bg-canvas px-5 pb-8 sm:px-8" />
@endsection

@section('content')
    @php
        $purchaseStatus = $purchase->status;
        $purchaseIsPaid = $purchase->isPaid();
        $purchaseIsPending = ! $purchaseStatus->isFinal();
        $purchaseStatusClass = $purchaseIsPaid
            ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
            : ($purchaseIsPending ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-rose-200 bg-rose-50 text-rose-900');
        $purchaseItems = $purchase->items;
        $purchaseLines = $purchaseItems->groupBy('class_pass_plan_id');
        $formatCartMoney = static fn (int $amountCents): string => \App\Support\MoneyFormatter::format($amountCents, $purchase->currency);
        $cartPaymentActionUrl = $paymentActionUrl ?? null;
        $cartStatusUrl = $statusUrl ?? null;
        $cartProvider = $purchase->provider;
    @endphp

    <main class="min-h-[calc(100vh-8rem)] bg-canvas px-5 py-8 text-slate-950 sm:px-8">
        <section class="mx-auto max-w-5xl">
            <x-ui.public-studio-header :account="$account" class="mb-6" />
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-semibold sm:text-3xl">{{ __('app.customer_cart_payment_title') }}</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.customer_cart_payment_help') }}</p>
                </div>
                <x-ui.button :href="route('customer.dashboard', $account->slug)" variant="secondary">
                    <x-ui.icon name="arrow-left" class="h-4 w-4" />
                    {{ __('app.customer_portal') }}
                </x-ui.button>
            </div>

            <div class="mt-6 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,0.75fr)]">
                <article class="min-w-0 rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
                    <h2 class="text-lg font-semibold">{{ __('app.customer_cart_summary') }}</h2>
                    <div class="mt-4 divide-y divide-stone-100">
                        @foreach ($purchaseLines as $purchaseLine)
                            <div class="flex flex-wrap items-start justify-between gap-3 py-4 first:pt-0">
                                <div class="min-w-0">
                                    <h3 class="font-semibold text-slate-950">{{ $purchaseLine->first()->plan_name }}</h3>
                                    <p class="mt-1 text-sm text-slate-500">{{ __('app.customer_cart_quantity') }}: {{ $purchaseLine->count() }}</p>
                                </div>
                                <div class="shrink-0 font-semibold tabular-nums">{{ $formatCartMoney((int) $purchaseLine->sum('amount_cents')) }}</div>
                            </div>
                        @endforeach
                    </div>
                    <dl class="mt-4 space-y-3 rounded-lg bg-slate-50 p-4 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">{{ __('app.subtotal') }}</dt>
                            <dd class="font-semibold tabular-nums">{{ $formatCartMoney((int) $purchase->subtotal_cents) }}</dd>
                        </div>
                        @if ((int) $purchase->discount_cents > 0)
                            <div class="flex items-center justify-between gap-3">
                                <dt class="text-slate-500">{{ __('app.promo_code_discount') }} @if ($purchase->promo_code) · {{ $purchase->promo_code }} @endif</dt>
                                <dd class="shrink-0 font-semibold tabular-nums text-emerald-700">−{{ $formatCartMoney((int) $purchase->discount_cents) }}</dd>
                            </div>
                        @endif
                        <div class="flex items-center justify-between gap-3 border-t border-stone-200 pt-3">
                            <dt class="font-semibold text-slate-950">{{ __('app.total') }}</dt>
                            <dd class="text-xl font-semibold tabular-nums">{{ $formatCartMoney((int) $purchase->amount_cents) }}</dd>
                        </div>
                    </dl>

                    @if ($purchaseIsPaid)
                        <section class="mt-6" aria-labelledby="customer-cart-pass-codes-title">
                            <h2 id="customer-cart-pass-codes-title" class="font-semibold">{{ __('app.customer_cart_issued_passes') }}</h2>
                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                @foreach ($purchaseItems as $purchaseItem)
                                    @if ($purchaseItem->customerClassPass)
                                        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                                            <div class="text-sm font-medium text-emerald-900">{{ $purchaseItem->plan_name }}</div>
                                            <div class="mt-1 font-mono text-lg font-semibold text-emerald-950">{{ $purchaseItem->customerClassPass->code }}</div>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </section>
                    @endif
                </article>

                <aside class="min-w-0 rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
                    <h2 class="text-lg font-semibold">{{ __('app.payment_status') }}</h2>
                    <div class="mt-4 rounded-lg border p-4 text-sm {{ $purchaseStatusClass }}" role="status">
                        @if ($purchaseIsPaid)
                            <p class="font-semibold">{{ __('app.customer_cart_paid') }}</p>
                        @elseif ($purchaseStatus === \App\Enums\CustomerPurchaseStatus::PaymentExpired)
                            <p class="font-semibold">{{ __('app.customer_cart_payment_expired') }}</p>
                        @else
                            <p class="font-semibold">{{ __('app.class_pass_checkout_status_'.$purchaseStatus->value) }}</p>
                        @endif
                        @if ($purchaseIsPending)
                            <p class="mt-2 leading-6">{{ __('app.class_pass_checkout_pending_help') }}</p>
                        @endif
                    </div>

                    @if ($purchaseIsPending && $cartPaymentActionUrl)
                        <form method="POST" action="{{ $cartPaymentActionUrl }}" class="mt-5 space-y-4" data-customer-cart-payment-form>
                            @csrf
                            @include('public._studio-rules-agreement')
                            @if ($errors->any())
                                <p class="text-sm font-semibold text-rose-700" role="alert">{{ $errors->first() }}</p>
                            @endif
                            <p class="hidden text-sm font-semibold text-amber-800" data-customer-cart-agreement-help>{{ __('app.studio_rules_accepted') }}</p>
                            <x-ui.button type="submit" variant="success" size="lg" class="w-full justify-start px-3" data-customer-cart-payment-action>
                                <x-ui.payment-brand :provider="$cartProvider" :label="config('integrations.providers.'.$cartProvider.'.label', $cartProvider)" presentation="card" class="w-full" />
                            </x-ui.button>
                            <x-ui.accepted-card-brands class="mt-5" />
                        </form>
                    @elseif ($purchaseIsPending)
                        <p class="mt-5 text-sm leading-6 text-slate-500">{{ __('app.customer_cart_payment_wait') }}</p>
                    @elseif (! $purchaseIsPaid)
                        <p class="mt-5 text-sm leading-6 text-slate-500">{{ __('app.customer_cart_contact_studio') }}</p>
                    @endif

                    @if ($purchaseIsPending && $cartStatusUrl)
                        <div class="mt-5" data-customer-cart-payment-poll data-status-url="{{ $cartStatusUrl }}">
                            <p class="text-sm text-slate-500">{{ __('app.class_pass_checkout_checking_status') }}</p>
                            <div class="mt-3 hidden rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" data-customer-cart-payment-timeout>
                                {{ __('app.class_pass_checkout_poll_timeout') }}
                            </div>
                            <x-ui.button :href="request()->fullUrl()" variant="secondary" class="mt-3 w-full">{{ __('app.refresh') }}</x-ui.button>
                        </div>
                    @endif
                </aside>
            </div>
        </section>
    </main>
@endsection

@php
    $cartPlans ??= $classPassPlans;
    $cartPaymentSettings ??= collect();
    $cartInitialLocationId = $locations->count() === 1 ? $locations->first()->id : ($workingLocationId ?? null);
@endphp

<div
    id="customer-cart-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/55 p-3 backdrop-blur-sm sm:p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="customer-cart-title"
    data-customer-cart
    data-quote-url="{{ route('dashboard.accounts.customers.cart.quote', [$account, $customer]) }}"
    data-checkout-url="{{ route('dashboard.accounts.customers.cart.checkout', [$account, $customer]) }}"
    data-quote-empty="{{ __('app.customer_cart_empty') }}"
    data-quote-loading="{{ __('app.customer_cart_calculating') }}"
    data-quote-stale="{{ __('app.customer_cart_quote_required') }}"
    data-request-failed="{{ __('app.customer_cart_request_failed') }}"
    data-checkout-uncertain="{{ __('app.customer_cart_checkout_uncertain') }}"
    data-receipt-required="{{ __('app.customer_cart_receipt_required') }}"
    data-paid-message="{{ __('app.customer_cart_paid') }}"
    data-pending-message="{{ __('app.customer_cart_pending') }}"
    data-status-loading="{{ __('app.class_pass_checkout_checking_status') }}"
    data-free-label="{{ __('app.customer_cart_free_count', ['count' => '__count__']) }}"
    data-provider-unavailable="{{ __('app.no_payment_methods_available') }}"
    data-poll-timeout="{{ __('app.class_pass_checkout_poll_timeout') }}"
>
    <div class="flex max-h-[92dvh] w-full max-w-5xl flex-col overflow-hidden rounded-xl border border-stone-200 bg-white shadow-2xl">
        <div class="flex shrink-0 items-start justify-between gap-4 border-b border-stone-200 p-5">
            <div>
                <h2 id="customer-cart-title" class="text-lg font-semibold text-slate-950">{{ __('app.customer_cart') }} · {{ $customer->name }}</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.customer_cart_help') }}</p>
            </div>
            <x-ui.action-button type="button" icon="close" :label="__('app.close')" data-customer-cart-close />
        </div>

        <form
            method="POST"
            action="{{ route('dashboard.accounts.customers.cart.checkout', [$account, $customer]) }}"
            class="flex min-h-0 flex-1 flex-col"
            data-customer-cart-form
        >
            @csrf
            <div class="min-h-0 flex-1 overflow-y-auto p-5">
                <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,0.85fr)]" data-customer-cart-editor>
                    <section class="min-w-0 space-y-4" aria-labelledby="customer-cart-products-title">
                        <h3 id="customer-cart-products-title" class="font-semibold text-slate-950">{{ __('app.customer_cart_products') }}</h3>
                        <div class="flex flex-col items-stretch gap-2 sm:flex-row sm:items-end">
                            <label class="min-w-0 flex-1">
                                <span class="crm-label">{{ __('app.class_pass_plan') }}</span>
                                <select class="crm-field" data-customer-cart-plan @disabled($cartPlans->isEmpty())>
                                    <option value="">{{ __('app.customer_cart_select_plan') }}</option>
                                    @foreach ($cartPlans as $cartPlan)
                                        <option
                                            value="{{ $cartPlan->id }}"
                                            data-name="{{ $cartPlan->name }}"
                                            data-price="{{ \App\Support\MoneyFormatter::format($cartPlan->price_cents, $cartPlan->currency) }}"
                                            data-is-trial="{{ $cartPlan->is_trial ? 'true' : 'false' }}"
                                        >{{ $cartPlan->name }} · {{ \App\Support\MoneyFormatter::format($cartPlan->price_cents, $cartPlan->currency) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <x-ui.button type="button" variant="secondary" data-customer-cart-add disabled>
                                <x-ui.icon name="plus" class="h-4 w-4" />
                                {{ __('app.add') }}
                            </x-ui.button>
                        </div>
                        <p class="text-sm text-slate-500" data-customer-cart-empty>{{ __('app.customer_cart_empty') }}</p>
                        <div class="space-y-3" data-customer-cart-lines></div>
                        <p class="text-xs leading-5 text-slate-500">{{ __('app.customer_cart_quantity_help') }}</p>

                        <label class="block">
                            <span class="crm-label">{{ __('app.issued_location') }}</span>
                            <select class="crm-field" data-customer-cart-location required>
                                <option value="">{{ __('app.location') }}</option>
                                @foreach ($locations as $location)
                                    <option value="{{ $location->id }}" @selected((string) $cartInitialLocationId === (string) $location->id)>{{ $location->name }}</option>
                                @endforeach
                            </select>
                        </label>

                        <div class="rounded-lg border border-stone-200 p-4">
                            <label class="block" for="customer-cart-promo">
                                <span class="crm-label">{{ __('app.promo_code') }}</span>
                            </label>
                            <div class="flex flex-col gap-2 sm:flex-row">
                                <input id="customer-cart-promo" type="text" autocomplete="off" maxlength="100" class="crm-field min-w-0 flex-1 uppercase" data-customer-cart-promo>
                                <x-ui.button type="button" variant="secondary" data-customer-cart-quote disabled>{{ __('app.customer_cart_update_total') }}</x-ui.button>
                            </div>
                        </div>
                    </section>

                    <aside class="min-w-0 space-y-4 rounded-xl border border-stone-200 bg-slate-50 p-4" aria-labelledby="customer-cart-summary-title">
                        <h3 id="customer-cart-summary-title" class="font-semibold text-slate-950">{{ __('app.customer_cart_summary') }}</h3>
                        <dl class="space-y-3 text-sm" aria-live="polite">
                            <div class="flex items-center justify-between gap-3">
                                <dt class="text-slate-500">{{ __('app.subtotal') }}</dt>
                                <dd class="font-semibold tabular-nums" data-customer-cart-subtotal>—</dd>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <dt class="text-slate-500">{{ __('app.promo_code_discount') }}</dt>
                                <dd class="font-semibold tabular-nums text-emerald-700" data-customer-cart-discount>—</dd>
                            </div>
                            <div class="flex items-center justify-between gap-3 border-t border-stone-200 pt-3">
                                <dt class="font-semibold text-slate-950">{{ __('app.total') }}</dt>
                                <dd class="text-xl font-semibold tabular-nums text-slate-950" data-customer-cart-total>—</dd>
                            </div>
                        </dl>

                        <div data-customer-cart-payment-fields>
                            <fieldset>
                                <legend class="crm-label">{{ __('app.payment_method') }}</legend>
                                <div class="mt-2 space-y-2">
                                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-stone-200 bg-white px-3 py-3 text-sm font-medium text-slate-800">
                                        <input type="radio" name="cart_payment_method" value="cash" class="crm-radio" checked data-customer-cart-method>
                                        {{ __('app.payment_method_cash') }}
                                    </label>
                                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-stone-200 bg-white px-3 py-3 text-sm font-medium text-slate-800">
                                        <input type="radio" name="cart_payment_method" value="card_transfer" class="crm-radio" data-customer-cart-method>
                                        {{ __('app.customer_cart_card_transfer') }}
                                    </label>
                                    <label class="flex items-center gap-3 rounded-lg border border-stone-200 bg-white px-3 py-3 text-sm font-medium text-slate-800 {{ $cartPaymentSettings->isEmpty() ? 'opacity-50' : 'cursor-pointer' }}">
                                        <input type="radio" name="cart_payment_method" value="online" class="crm-radio" @disabled($cartPaymentSettings->isEmpty()) data-customer-cart-method>
                                        {{ __('app.customer_cart_provider_qr') }}
                                    </label>
                                </div>
                            </fieldset>

                            <div class="mt-4 hidden" data-customer-cart-provider-fields>
                                <label class="block">
                                    <span class="crm-label">{{ __('app.customer_cart_provider') }}</span>
                                    <select class="crm-field" data-customer-cart-provider>
                                        @foreach ($cartPaymentSettings as $cartSetting)
                                            @php
                                                $cartProvider = $cartSetting->provider->value;
                                            @endphp
                                            <option value="{{ $cartProvider }}">{{ config('integrations.providers.'.$cartProvider.'.label', $cartProvider) }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <p class="mt-2 text-xs leading-5 text-slate-500">{{ __('app.customer_cart_provider_qr_help') }}</p>
                            </div>

                            <label class="mt-4 flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3" data-customer-cart-receipt-fields>
                                <input type="checkbox" class="crm-checkbox mt-0.5" data-customer-cart-receipt>
                                <span class="text-sm leading-6 text-amber-900" data-customer-cart-cash-receipt>{{ __('app.customer_cart_cash_received') }}</span>
                                <span class="hidden text-sm leading-6 text-amber-900" data-customer-cart-transfer-receipt>{{ __('app.customer_cart_transfer_received') }}</span>
                            </label>

                            @if ($cartPaymentSettings->isEmpty())
                                <p class="mt-3 text-xs leading-5 text-slate-500">{{ __('app.no_payment_methods_available') }}</p>
                            @endif
                        </div>
                        <p class="hidden text-sm font-medium text-emerald-800" data-customer-cart-free-help>{{ __('app.customer_cart_no_payment_required') }}</p>
                    </aside>
                </div>

                <section class="hidden space-y-5" data-customer-cart-result aria-live="polite">
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" data-customer-cart-result-message></div>
                    <div class="hidden rounded-xl border border-stone-200 bg-slate-50 p-5 text-center" data-customer-cart-qr-block>
                        <h3 class="font-semibold text-slate-950">{{ __('app.customer_cart_show_qr') }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.customer_cart_show_qr_help') }}</p>
                        <img class="mx-auto mt-4 h-56 w-56 max-w-full rounded-lg border border-stone-200 bg-white p-3" alt="{{ __('app.customer_cart_qr_alt') }}" data-customer-cart-qr>
                        <x-ui.button href="#" variant="secondary" class="mt-4" target="_blank" rel="noopener noreferrer" data-customer-cart-payment-link>
                            <x-ui.icon name="external-link" class="h-4 w-4" />
                            {{ __('app.customer_cart_open_payment') }}
                        </x-ui.button>
                    </div>
                    <div class="hidden" data-customer-cart-issued>
                        <h3 class="font-semibold text-slate-950">{{ __('app.customer_cart_issued_passes') }}</h3>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2" data-customer-cart-issued-items></div>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <x-ui.button type="button" variant="secondary" data-customer-cart-refresh>{{ __('app.refresh') }}</x-ui.button>
                        <x-ui.button :href="route('dashboard.accounts.customers.edit', [$account, $customer])" variant="secondary" class="hidden" data-customer-cart-view-passes>{{ __('app.customer_cart_view_passes') }}</x-ui.button>
                        <x-ui.button type="button" variant="secondary" class="hidden" data-customer-cart-new>{{ __('app.customer_cart_new') }}</x-ui.button>
                    </div>
                    <p class="hidden text-sm text-slate-500" data-customer-cart-poll-timeout>{{ __('app.class_pass_checkout_poll_timeout') }}</p>
                </section>
            </div>

            <div class="shrink-0 space-y-3 border-t border-stone-200 bg-white p-5">
                <div class="hidden rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800" role="alert" data-customer-cart-error></div>
                <p class="text-sm text-slate-500" aria-live="polite" data-customer-cart-feedback>{{ __('app.customer_cart_empty') }}</p>
                <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <x-ui.button type="button" variant="secondary" data-customer-cart-close>{{ __('app.close') }}</x-ui.button>
                    <x-ui.button type="submit" variant="success" data-customer-cart-checkout disabled>
                        <x-ui.icon name="check" class="h-4 w-4" />
                        {{ __('app.customer_cart_checkout') }}
                    </x-ui.button>
                </div>
            </div>
        </form>
    </div>
</div>

<template data-customer-cart-line-template>
    <div class="rounded-lg border border-stone-200 bg-white p-4" data-customer-cart-line>
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="font-semibold text-slate-950" data-customer-cart-line-name></div>
                <div class="mt-1 text-xs text-slate-500" data-customer-cart-line-price></div>
                <span class="crm-status-scheduled mt-2 hidden" data-customer-cart-line-trial>{{ __('app.trial_class_pass_short') }}</span>
            </div>
            <x-ui.button type="button" variant="ghost" size="sm" data-customer-cart-line-remove>{{ __('app.remove') }}</x-ui.button>
        </div>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
            <label class="flex items-center gap-2">
                <span class="text-sm text-slate-600">{{ __('app.customer_cart_quantity') }}</span>
                <input type="number" min="1" step="1" inputmode="numeric" value="1" class="crm-field w-24 text-center tabular-nums" data-customer-cart-line-quantity>
            </label>
            <div class="text-right text-sm">
                <div class="font-semibold tabular-nums text-slate-950" data-customer-cart-line-total>—</div>
                <div class="mt-1 hidden text-xs font-semibold text-emerald-700" data-customer-cart-line-free></div>
            </div>
        </div>
    </div>
</template>

<template data-customer-cart-issued-template>
    <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3">
        <div class="text-sm font-medium text-emerald-900" data-customer-cart-issued-name></div>
        <div class="mt-1 font-mono text-lg font-semibold text-emerald-950" data-customer-cart-issued-code></div>
    </div>
</template>

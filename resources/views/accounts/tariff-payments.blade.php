@extends('layouts.app')

@section('title', __('app.tariff_payments').' - '.$account->name)

@section('content')
    @php
        $plan = $subscription?->plan;
        $timezone = $account->timezone ?? config('app.timezone');
        $formatMoney = fn (?int $cents, ?string $currency): string => \App\Support\MoneyFormatter::format($cents, $currency ?: $account->default_currency);
        $statusClass = match ($subscription?->status?->value) {
            'active' => 'crm-status-active',
            'trialing', 'pending_payment' => 'crm-status-scheduled',
            'past_due' => 'crm-status-warning',
            'expired', 'suspended', 'cancelled' => 'crm-status-danger',
            default => 'crm-status-muted',
        };
        $isBillingV2 = $subscription?->usesLocationBilling() ?? false;
        $isPromo = $plan?->plan_type === \App\Enums\SubscriptionPlanType::Promo;
        $paymentTargetPlan = $plan?->plan_type === \App\Enums\SubscriptionPlanType::Standard ? $plan : $standardPlan;
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.tariff_payments') }}</h1>
            <p class="crm-page-copy">{{ $isBillingV2 ? __('app.billing_v2_owner_copy') : __('app.tariff_payments_copy') }}</p>
        </div>
        @if ($supportUrl)
            <x-ui.button :href="$supportUrl" variant="secondary">{{ __('app.support') }}</x-ui.button>
        @endif
    </div>

    @foreach (['provider', 'billing'] as $errorBagKey)
        @error($errorBagKey)
            <div class="mt-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-900 shadow-xs">{{ $message }}</div>
        @enderror
    @endforeach

    @if ($isBillingV2 && $billingV2Quotes)
        @if ($subscription->status === \App\Enums\SubscriptionStatus::Trialing)
            <x-ui.panel padding="lg" class="mt-6 border-emerald-200 bg-emerald-50/60">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div class="crm-page-kicker">
                            {{ trans_choice('app.free_trial_days_count', $subscription->priceVersion->trial_days, ['count' => $subscription->priceVersion->trial_days]) }}
                        </div>
                        <h2 class="mt-1 text-xl font-semibold text-slate-950">
                            {{ __('app.trial_ends_exactly', ['date' => $subscription->trial_ends_at?->timezone($timezone)->format('d.m.Y H:i')]) }}
                        </h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('app.trial_no_card_charge_copy') }}</p>
                    </div>
                    <span class="crm-status-active">{{ __('app.no_card_required') }}</span>
                </div>
            </x-ui.panel>
        @elseif ($subscription->isInGracePeriod())
            <x-ui.panel padding="lg" class="mt-6 border-amber-200 bg-amber-50/60">
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.subscription_grace_title') }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    {{ __('app.subscription_grace_copy', ['date' => $subscription->grace_ends_at?->timezone($timezone)->format('d.m.Y H:i')]) }}
                </p>
            </x-ui.panel>
        @endif

        @if ($subscription->pendingPriceVersion && $pendingTariffQuote)
            <x-ui.panel padding="lg" class="mt-6 border-amber-200 bg-amber-50/60">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div class="crm-page-kicker">{{ __('app.scheduled_tariff_change') }}</div>
                        <h2 class="mt-1 text-lg font-semibold text-slate-950">{{ $subscription->pendingPriceVersion->plan?->name }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">
                            {{ __('app.scheduled_tariff_change_owner_copy', [
                                'date' => $subscription->pending_tariff_change_at?->timezone($timezone)->format('d.m.Y') ?? __('app.not_set'),
                                'amount' => $formatMoney($pendingTariffQuote->finalAmountCents, $pendingTariffQuote->currency),
                                'locations' => $activeLocationCount,
                            ]) }}
                        </p>
                    </div>
                    <span class="crm-status-scheduled">{{ __('app.scheduled') }}</span>
                </div>
            </x-ui.panel>
        @endif

        <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <x-ui.metric
                :label="__('app.current_tariff')"
                :value="$plan?->name ?? __('app.not_set')"
                :meta="$plan?->public_signup_enabled ? __('app.public_tariff') : __('app.private_tariff')"
                icon="payments"
                accent="slate"
            />
            <x-ui.metric
                :label="__('app.active_billable_locations')"
                :value="$activeLocationCount"
                :meta="__('app.active_locations_definition')"
                icon="locations"
                accent="emerald"
            />
            <x-ui.metric
                :label="__('app.monthly_price')"
                :value="$formatMoney($billingV2Quotes['monthly']->finalAmountCents, $billingV2Quotes['monthly']->currency)"
                :meta="__('app.all_features_included')"
                icon="payments"
                accent="brand"
            />
            <x-ui.metric
                :label="__('app.annual_price')"
                :value="$formatMoney($billingV2Quotes['annual']->finalAmountCents, $billingV2Quotes['annual']->currency)"
                :meta="__('app.annual_saving_amount', ['amount' => $formatMoney($billingV2Quotes['annual']->discountCents, $billingV2Quotes['annual']->currency)])"
                icon="class-pass-plans"
                accent="amber"
            />
            <x-ui.metric
                :label="__('app.next_charge')"
                :value="$subscription->next_payment_at?->timezone($timezone)->format('d.m.Y H:i') ?? __('app.not_scheduled')"
                :meta="$subscription->auto_renew_enabled ? __('app.auto_renew_enabled') : __('app.auto_renew_disabled')"
                icon="schedule"
                accent="slate"
            />
        </section>

        <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(320px,.8fr)]">
            <x-ui.panel padding="lg">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.choose_subscription_interval') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">
                            {{ __('app.first_charge_disclosure', [
                                'date' => ($subscription->trial_ends_at?->isFuture() ? $subscription->trial_ends_at : now())->timezone($timezone)->format('d.m.Y'),
                                'locations' => $activeLocationCount,
                            ]) }}
                        </p>
                    </div>
                    <span class="{{ $statusClass }}">{{ __('app.'.$subscription->status->value) }}</span>
                </div>

                <form method="POST" action="{{ route('dashboard.accounts.tariff-payments.subscribe', $account) }}" class="mt-6 space-y-4">
                    @csrf
                    <label class="block rounded-xl border border-stone-200 bg-white p-4">
                        <span class="flex items-start gap-3">
                            <input type="radio" name="billing_interval" value="monthly" class="mt-1" @checked(old('billing_interval', $subscription->billing_interval_v2?->value ?? 'monthly') === 'monthly')>
                            <span>
                                <span class="block font-semibold text-slate-950">{{ __('app.monthly') }} · {{ $formatMoney($billingV2Quotes['monthly']->finalAmountCents, $billingV2Quotes['monthly']->currency) }}</span>
                                <span class="mt-1 block text-sm text-slate-500">{{ __('app.monthly_renewal_copy') }}</span>
                            </span>
                        </span>
                    </label>
                    <label class="block rounded-xl border border-emerald-200 bg-emerald-50/50 p-4">
                        <span class="flex items-start gap-3">
                            <input type="radio" name="billing_interval" value="annual" class="mt-1" @checked(old('billing_interval', $subscription->billing_interval_v2?->value) === 'annual')>
                            <span>
                                <span class="block font-semibold text-slate-950">{{ __('app.annual') }} · {{ $formatMoney($billingV2Quotes['annual']->finalAmountCents, $billingV2Quotes['annual']->currency) }}</span>
                                <span class="mt-1 block text-sm text-slate-500">{{ __('app.annual_renewal_copy', ['percent' => $billingV2Quotes['annual']->annualDiscountPercent]) }}</span>
                            </span>
                        </span>
                    </label>

                    <p class="text-xs leading-5 text-slate-500">{{ __('app.subscription_consent_copy') }}</p>
                    <x-ui.button type="submit">
                        <x-ui.icon name="payments" class="h-4 w-4" />
                        {{ $paymentMethod?->isActive() ? __('app.confirm_subscription') : __('app.verify_card_zero_uah') }}
                    </x-ui.button>
                </form>
            </x-ui.panel>

            <x-ui.panel padding="lg">
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.payment_method_and_cancellation') }}</h2>
                <dl class="mt-5 space-y-4 text-sm">
                    <div>
                        <dt class="text-slate-500">{{ __('app.saved_payment_method') }}</dt>
                        <dd class="mt-1 font-semibold text-slate-950">
                            {{ $paymentMethod?->isActive() ? trim(($paymentMethod->card_brand ? strtoupper($paymentMethod->card_brand).' ' : '').$paymentMethod->masked_pan) : __('app.not_set') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">{{ __('app.cancellation_rule') }}</dt>
                        <dd class="mt-1 text-slate-700">{{ __('app.cancellation_at_period_end_no_refund') }}</dd>
                    </div>
                </dl>

                @if ($subscription->cancel_at_period_end)
                    <form method="POST" action="{{ route('dashboard.accounts.tariff-payments.resume', $account) }}" class="mt-6">
                        @csrf
                        <x-ui.button type="submit" variant="secondary">{{ __('app.resume_subscription') }}</x-ui.button>
                    </form>
                    <p class="mt-3 text-sm text-amber-800">{{ __('app.cancellation_effective_date', ['date' => $subscription->ends_at?->timezone($timezone)->format('d.m.Y')]) }}</p>
                @elseif ($subscription->ends_at?->isFuture())
                    <form method="POST" action="{{ route('dashboard.accounts.tariff-payments.cancel', $account) }}" class="mt-6" data-confirm-delete>
                        @csrf
                        @method('DELETE')
                        <x-ui.button type="submit" variant="danger">{{ __('app.cancel_subscription') }}</x-ui.button>
                    </form>
                @endif
            </x-ui.panel>
        </div>

        @if ($pendingLocationUpgrades->isNotEmpty())
            <x-ui.panel padding="none" class="mt-6 overflow-hidden">
                <div class="border-b border-stone-100 p-5">
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('app.pending_location_upgrades') }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ __('app.pending_location_upgrades_copy') }}</p>
                </div>
                @foreach ($pendingLocationUpgrades as $pendingLocation)
                    <div class="crm-row lg:grid-cols-[1fr_180px_auto] lg:items-center">
                        <div>
                            <div class="font-semibold text-slate-950">{{ $pendingLocation->name }}</div>
                            <div class="mt-1 text-sm text-slate-500">{{ $pendingLocation->address }}</div>
                        </div>
                        <div class="text-sm font-semibold text-slate-700">
                            {{ __('app.prorated_charge_now', ['amount' => $formatMoney($locationUpgradeQuotes[$pendingLocation->id] ?? null, $subscription->priceVersion->currency)]) }}
                        </div>
                        <form method="POST" action="{{ route('dashboard.accounts.tariff-payments.locations.approve', [$account, $pendingLocation]) }}">
                            @csrf
                            <x-ui.button type="submit">{{ __('app.approve_and_pay') }}</x-ui.button>
                        </form>
                    </div>
                @endforeach
            </x-ui.panel>
        @endif
    @else
        <section class="mt-6 grid gap-4 lg:grid-cols-4">
            <x-ui.metric :label="__('app.subscription_plan')" :value="$plan?->name ?? __('app.not_set')" icon="payments" accent="emerald" />
            <x-ui.metric :label="__('app.subscription_status')" :value="$subscription?->status ? __('app.'.$subscription->status->value) : __('app.not_set')" icon="bell" accent="slate" />
            <x-ui.metric :label="__('app.subscription_price')" :value="$plan ? $formatMoney($plan->price_cents, $plan->currency) : __('app.not_set')" icon="class-pass-plans" accent="brand" />
            <x-ui.metric :label="__('app.end_date')" :value="$subscription?->ends_at?->timezone($timezone)->format('Y-m-d') ?? __('app.not_set')" icon="schedule" accent="amber" />
        </section>

        <x-ui.panel padding="lg" class="mt-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('app.current_subscription') }}</h2>
                    @if ($plan?->plan_type)
                        <span class="crm-status-muted mt-3">{{ __('app.subscription_plan_type_'.$plan->plan_type->value) }}</span>
                    @endif
                    <p class="mt-2 text-sm leading-6 text-slate-500">
                        {{ $requiresInitialDemoPayment ? __('app.legacy_demo_payment_retired') : ($isPromo ? __('app.subscription_promo_copy') : __('app.subscription_payment_copy')) }}
                    </p>
                </div>
                @if (! $isPromo && ! $requiresInitialDemoPayment)
                    <form method="POST" action="{{ route('dashboard.accounts.tariff-payments.pay-now', $account) }}">
                        @csrf
                        <x-ui.button type="submit">{{ $paymentTargetPlan->requires_recurring_payment ? __('app.subscribe_or_pay_now') : __('app.pay_now') }}</x-ui.button>
                    </form>
                @endif
            </div>
        </x-ui.panel>
    @endif

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
            <div class="crm-row lg:grid-cols-[minmax(0,1fr)_130px_150px_170px_auto] lg:items-center">
                <div>
                    <div class="font-semibold text-slate-950">{{ $payment->plan_name_snapshot ?: ($payment->plan?->name ?? __('app.not_set')) }}</div>
                    <div class="mt-1 text-sm text-slate-500">
                        {{ $payment->order_id }}
                        @if ($payment->billable_location_count)
                            · {{ trans_choice('app.billable_locations_count', $payment->billable_location_count, ['count' => $payment->billable_location_count]) }}
                        @endif
                    </div>
                </div>
                <div class="text-sm font-semibold text-slate-700">{{ $formatMoney($payment->amount_cents, $payment->currency) }}</div>
                <div class="text-sm text-slate-500">{{ __('app.'.$payment->payment_type->value) }}</div>
                <div class="text-sm text-slate-500">
                    @if ($payment->period_starts_at && $payment->period_ends_at)
                        {{ $payment->period_starts_at->timezone($timezone)->format('d.m.Y') }}–{{ $payment->period_ends_at->timezone($timezone)->format('d.m.Y') }}
                    @else
                        {{ $payment->paid_at?->timezone($timezone)->format('Y-m-d H:i') ?? $payment->started_at?->timezone($timezone)->format('Y-m-d H:i') ?? __('app.not_set') }}
                    @endif
                </div>
                <span class="{{ $paymentStatusClass }}">{{ __('app.'.$payment->status->value) }}</span>
                @if ($payment->status === \App\Enums\AccountSubscriptionPaymentStatus::PaymentPending && filled(data_get($payment->gateway_checkout_payload, 'response.pageUrl')))
                    <x-ui.button :href="data_get($payment->gateway_checkout_payload, 'response.pageUrl')" size="sm">{{ __('app.continue_payment') }}</x-ui.button>
                @endif
            </div>
        @empty
            <x-ui.empty-state :title="__('app.no_subscription_payments')" icon="payments" class="m-5" />
        @endforelse
    </x-ui.panel>

    @if ($payments->hasPages())
        <div class="mt-6">{{ $payments->links() }}</div>
    @endif
@endsection

<?php

namespace App\Http\Controllers;

use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\SubscriptionBillingInterval;
use App\Enums\SubscriptionPlanType;
use App\Models\Account;
use App\Models\SystemSetting;
use App\Support\SaasBilling\AccountSubscriptionAccess;
use App\Support\SaasBilling\CreateAccountSubscriptionPayment;
use App\Support\SaasBilling\MonopaySaasBilling;
use App\Support\SaasBilling\SaasBillingPlans;
use App\Support\SaasBilling\StartAccountSubscriptionPaymentCheckout;
use App\Support\SaasBilling\SubscriptionPricingCalculator;
use App\Support\SaasBilling\SubscriptionProrationPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class AccountTariffPaymentController extends Controller
{
    public function show(
        Request $request,
        Account $account,
        SaasBillingPlans $plans,
        AccountSubscriptionAccess $subscriptionAccess,
        SubscriptionPricingCalculator $pricing,
    ): View {
        $this->authorize('view', $account);
        abort_unless($account->isOwnedBy($request->user()), 403);

        $account->loadMissing([
            'subscription.plan',
            'subscription.priceVersion.tiers',
            'subscription.pendingPriceVersion.plan',
            'subscription.pendingPriceVersion.tiers',
            'subscription.paymentMethod',
            'subscriptionPayments.plan',
        ]);
        $subscription = $account->subscription;
        $activeLocationCount = max(1, $account->locations()->active()->count());
        $billingV2Quotes = null;
        $pendingTariffQuote = null;
        $locationUpgradeQuotes = [];

        if ($subscription?->usesLocationBilling() && $subscription->priceVersion) {
            $billingV2Quotes = [
                'monthly' => $pricing->calculate(
                    $subscription->priceVersion,
                    $activeLocationCount,
                    SubscriptionBillingInterval::Monthly,
                ),
                'annual' => $pricing->calculate(
                    $subscription->priceVersion,
                    $activeLocationCount,
                    SubscriptionBillingInterval::Annual,
                ),
            ];

            if ($subscription->pendingPriceVersion && $subscription->billing_interval_v2) {
                $pendingTariffQuote = $pricing->calculate(
                    $subscription->pendingPriceVersion,
                    $activeLocationCount,
                    $subscription->billing_interval_v2,
                );
            }

            $lastPaidPeriodStart = $account->subscriptionPayments()
                ->where('status', AccountSubscriptionPaymentStatus::PaymentPaid->value)
                ->latest('id')
                ->first()?->period_starts_at;

            if ($subscription->billing_interval_v2 && $subscription->ends_at?->isFuture() && ($lastPaidPeriodStart || $subscription->started_at)) {
                $proration = new SubscriptionProrationPeriod(
                    $lastPaidPeriodStart ?? $subscription->started_at,
                    $subscription->ends_at,
                    now(),
                );
                $currentQuote = $pricing->calculate(
                    $subscription->priceVersion,
                    max(1, (int) $subscription->billable_location_count),
                    $subscription->billing_interval_v2,
                    $proration,
                );
                $upgradeQuote = $pricing->calculate(
                    $subscription->priceVersion,
                    max(1, (int) $subscription->billable_location_count) + 1,
                    $subscription->billing_interval_v2,
                    $proration,
                );
                $locationUpgradeQuotes = $account->locations()
                    ->where('billing_activation_pending', true)
                    ->pluck('id')
                    ->mapWithKeys(fn (int $locationId): array => [
                        $locationId => $upgradeQuote->finalAmountCents - $currentQuote->finalAmountCents,
                    ])
                    ->all();
            }
        }

        return view('accounts.tariff-payments', [
            'account' => $account,
            'subscription' => $subscription,
            'activeLocationCount' => $activeLocationCount,
            'billingV2Quotes' => $billingV2Quotes,
            'pendingTariffQuote' => $pendingTariffQuote,
            'paymentMethod' => $subscription?->paymentMethod,
            'pendingLocationUpgrades' => $account->locations()
                ->where('billing_activation_pending', true)
                ->orderBy('name')
                ->get(),
            'locationUpgradeQuotes' => $locationUpgradeQuotes,
            'standardPlan' => $plans->standardPlan(),
            'requiresInitialDemoPayment' => $subscriptionAccess->requiresInitialDemoPayment($account),
            'pendingDemoPayment' => $account->subscriptionPayments()
                ->where('payment_type', AccountSubscriptionPaymentType::DemoInitial->value)
                ->whereIn('status', [
                    AccountSubscriptionPaymentStatus::PaymentStarted->value,
                    AccountSubscriptionPaymentStatus::PaymentPending->value,
                ])
                ->whereNotNull('gateway_checkout_payload')
                ->latest()
                ->first(),
            'payments' => $account->subscriptionPayments()
                ->with('plan')
                ->latest()
                ->paginate(15),
            'supportUrl' => SystemSetting::stringValue(SystemSetting::SupportUrlKey),
        ]);
    }

    public function payNow(
        Request $request,
        Account $account,
        SaasBillingPlans $plans,
        CreateAccountSubscriptionPayment $createPayment,
        AccountSubscriptionAccess $subscriptionAccess,
        MonopaySaasBilling $billing,
        StartAccountSubscriptionPaymentCheckout $startCheckout,
    ): RedirectResponse {
        $this->authorize('view', $account);
        abort_unless($account->isOwnedBy($request->user()), 403);

        $account->loadMissing(['signupRequests', 'subscription.plan']);

        $plan = $account->subscription?->plan;

        if ($plan?->plan_type === SubscriptionPlanType::Promo) {
            return redirect()
                ->route('dashboard.accounts.tariff-payments.show', $account)
                ->with('status', __('app.subscription_promo_no_payment_required'));
        }

        $targetPlan = $plan?->plan_type === SubscriptionPlanType::Standard
            ? $plan
            : $plans->standardPlan();

        $setting = $billing->platformSetting();

        if (! $setting) {
            throw ValidationException::withMessages([
                'provider' => __('app.payment_provider_unavailable'),
            ]);
        }

        if ($subscriptionAccess->requiresInitialDemoPayment($account)) {
            return redirect()
                ->route('dashboard.accounts.tariff-payments.show', $account)
                ->withErrors(['billing' => __('app.legacy_demo_payment_retired')]);
        }

        $payment = $createPayment->execute(
            $account,
            $targetPlan,
            $targetPlan->requires_recurring_payment
                ? AccountSubscriptionPaymentType::FullSubscription
                : AccountSubscriptionPaymentType::ManualRenewal,
        );

        try {
            $checkout = $startCheckout->execute($payment, $setting, route('dashboard.accounts.tariff-payments.show', $account));
        } catch (Throwable $exception) {
            $payment->forceFill([
                'status' => AccountSubscriptionPaymentStatus::PaymentFailed,
                'failure_reason' => $exception->getMessage(),
                'failed_at' => now(),
            ])->save();

            throw ValidationException::withMessages([
                'provider' => __('app.payment_start_failed'),
            ]);
        }

        return redirect()->away($checkout->url);
    }
}

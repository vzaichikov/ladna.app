<?php

namespace App\Http\Controllers;

use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\SubscriptionPlanType;
use App\Models\Account;
use App\Models\AccountSubscriptionPayment;
use App\Models\SystemSetting;
use App\Support\SaasBilling\AccountSubscriptionAccess;
use App\Support\SaasBilling\CreateAccountSubscriptionPayment;
use App\Support\SaasBilling\CreateDemoSignup;
use App\Support\SaasBilling\MonopaySaasBilling;
use App\Support\SaasBilling\SaasBillingPlans;
use App\Support\SaasBilling\StartAccountSubscriptionPaymentCheckout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class AccountTariffPaymentController extends Controller
{
    public function show(Request $request, Account $account, SaasBillingPlans $plans, AccountSubscriptionAccess $subscriptionAccess): View
    {
        $this->authorize('view', $account);
        abort_unless($account->isOwnedBy($request->user()), 403);

        $account->loadMissing([
            'subscription.plan',
            'subscriptionPayments.plan',
        ]);

        return view('accounts.tariff-payments', [
            'account' => $account,
            'subscription' => $account->subscription,
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
        CreateDemoSignup $createDemoSignup,
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

        $payment = $subscriptionAccess->requiresInitialDemoPayment($account)
            ? $this->createDemoPayment($account, $createDemoSignup)
            : $createPayment->execute(
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

        if ($payment->payment_type === AccountSubscriptionPaymentType::DemoInitial) {
            return redirect()
                ->route('dashboard.accounts.tariff-payments.show', $account)
                ->with('status', __('app.demo_payment_started'));
        }

        return redirect()->away($checkout->url);
    }

    private function createDemoPayment(Account $account, CreateDemoSignup $createDemoSignup): AccountSubscriptionPayment
    {
        $signup = $account->signupRequests()
            ->with(['account.subscription', 'plan'])
            ->latest()
            ->firstOrFail();

        return $createDemoSignup->createPayment($signup);
    }
}

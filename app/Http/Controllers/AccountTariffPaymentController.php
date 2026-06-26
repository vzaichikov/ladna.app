<?php

namespace App\Http\Controllers;

use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\SubscriptionPlanType;
use App\Models\Account;
use App\Models\SystemSetting;
use App\Support\SaasBilling\CreateAccountSubscriptionPayment;
use App\Support\SaasBilling\MonopaySaasBilling;
use App\Support\SaasBilling\SaasBillingPlans;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class AccountTariffPaymentController extends Controller
{
    public function show(Request $request, Account $account, SaasBillingPlans $plans): View
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
        MonopaySaasBilling $billing,
    ): RedirectResponse {
        $this->authorize('view', $account);
        abort_unless($account->isOwnedBy($request->user()), 403);

        $account->loadMissing('subscription.plan');

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

        $type = $targetPlan->requires_recurring_payment
            ? AccountSubscriptionPaymentType::FullSubscription
            : AccountSubscriptionPaymentType::ManualRenewal;
        $payment = $createPayment->execute($account, $targetPlan, $type);

        try {
            $checkout = $targetPlan->requires_recurring_payment
                ? $billing->startRecurringPayment($payment, $setting, route('dashboard.accounts.tariff-payments.show', $account))
                : $billing->startOneTimePayment($payment, $setting, route('dashboard.accounts.tariff-payments.show', $account));
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

        $payload = $checkout->gatewayPayload;
        $response = is_array($payload['response'] ?? null) ? $payload['response'] : [];

        $payment->forceFill([
            'gateway_invoice_id' => is_string($response['invoiceId'] ?? null) ? $response['invoiceId'] : null,
            'gateway_subscription_id' => is_string($response['subscriptionId'] ?? null) ? $response['subscriptionId'] : null,
            'gateway_status' => is_string($response['status'] ?? null) ? $response['status'] : null,
            'gateway_checkout_payload' => $payload,
        ])->save();

        return redirect()->away($checkout->url);
    }
}

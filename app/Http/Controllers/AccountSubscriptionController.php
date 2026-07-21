<?php

namespace App\Http\Controllers;

use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\SubscriptionBillingInterval;
use App\Enums\SubscriptionStatus;
use App\Http\Requests\ApproveLocationUpgradeRequest;
use App\Http\Requests\StartAccountSubscriptionRequest;
use App\Models\Account;
use App\Models\Location;
use App\Support\SaasBilling\ApproveLocationUpgrade;
use App\Support\SaasBilling\CancelAccountSubscription;
use App\Support\SaasBilling\ChargeAccountSubscription;
use App\Support\SaasBilling\CreateBillingV2Payment;
use App\Support\SaasBilling\MonopaySaasBilling;
use App\Support\SaasBilling\StartPaymentMethodVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

class AccountSubscriptionController extends Controller
{
    public function subscribe(
        StartAccountSubscriptionRequest $request,
        Account $account,
        MonopaySaasBilling $billing,
        StartPaymentMethodVerification $startVerification,
        CreateBillingV2Payment $createPayment,
        ChargeAccountSubscription $chargeSubscription,
    ): RedirectResponse {
        $account->loadMissing(['subscription.paymentMethod', 'subscription.plan']);
        $subscription = $account->subscription;
        $interval = SubscriptionBillingInterval::from($request->string('billing_interval')->toString());
        $setting = $billing->platformSetting();

        if (! config('ladna.saas_billing_v2_enabled') || ! $subscription?->usesLocationBilling() || ! $setting) {
            throw ValidationException::withMessages(['billing' => __('app.payment_provider_unavailable')]);
        }

        try {
            if (! $subscription->paymentMethod?->isActive()) {
                $checkout = $startVerification->execute(
                    $subscription,
                    $interval,
                    $setting,
                    route('dashboard.accounts.tariff-payments.show', $account),
                );

                return redirect()->away($checkout->url);
            }

            $subscription->forceFill([
                'billing_interval_v2' => $interval,
                'auto_renew_enabled' => true,
                'next_payment_at' => $subscription->trial_ends_at?->isFuture()
                    ? $subscription->trial_ends_at
                    : now(),
            ])->save();

            if ($subscription->status === SubscriptionStatus::Trialing && $subscription->trial_ends_at?->isFuture()) {
                return redirect()
                    ->route('dashboard.accounts.tariff-payments.show', $account)
                    ->with('status', __('app.subscription_scheduled_after_trial'));
            }

            $payment = $createPayment->execute(
                $subscription,
                AccountSubscriptionPaymentType::FullSubscription,
            );
            $redirectUrl = $chargeSubscription->execute(
                $payment,
                $setting,
                route('dashboard.accounts.tariff-payments.show', $account),
                true,
            );

            return $redirectUrl
                ? redirect()->away($redirectUrl)
                : redirect()->route('dashboard.accounts.tariff-payments.show', $account)
                    ->with('status', __('app.subscription_payment_processing'));
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['billing' => __('app.payment_start_failed')]);
        }
    }

    public function cancel(Request $request, Account $account, CancelAccountSubscription $cancelSubscription): RedirectResponse
    {
        $this->authorizeOwner($request, $account);

        try {
            $cancelSubscription->request($account->subscription()->firstOrFail());
        } catch (LogicException $exception) {
            throw ValidationException::withMessages(['billing' => $exception->getMessage()]);
        }

        return redirect()->route('dashboard.accounts.tariff-payments.show', $account)
            ->with('status', __('app.subscription_cancellation_scheduled'));
    }

    public function resume(Request $request, Account $account, CancelAccountSubscription $cancelSubscription): RedirectResponse
    {
        $this->authorizeOwner($request, $account);

        try {
            $cancelSubscription->resume($account->subscription()->firstOrFail());
        } catch (LogicException $exception) {
            throw ValidationException::withMessages(['billing' => $exception->getMessage()]);
        }

        return redirect()->route('dashboard.accounts.tariff-payments.show', $account)
            ->with('status', __('app.subscription_cancellation_reversed'));
    }

    public function approveLocation(
        ApproveLocationUpgradeRequest $request,
        Account $account,
        Location $location,
        MonopaySaasBilling $billing,
        ApproveLocationUpgrade $approveUpgrade,
    ): RedirectResponse {
        $setting = $billing->platformSetting();

        if (! $setting) {
            throw ValidationException::withMessages(['billing' => __('app.payment_provider_unavailable')]);
        }

        try {
            $redirectUrl = $approveUpgrade->execute(
                $account,
                $location,
                $setting,
                route('dashboard.accounts.tariff-payments.show', $account),
            );
        } catch (LogicException $exception) {
            throw ValidationException::withMessages(['billing' => $exception->getMessage()]);
        }

        return $redirectUrl
            ? redirect()->away($redirectUrl)
            : redirect()->route('dashboard.accounts.tariff-payments.show', $account)
                ->with('status', __('app.location_upgrade_payment_processing'));
    }

    private function authorizeOwner(Request $request, Account $account): void
    {
        $this->authorize('view', $account);
        abort_unless($account->isOwnedBy($request->user()), 403);
    }
}

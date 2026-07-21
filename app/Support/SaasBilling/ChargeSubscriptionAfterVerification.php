<?php

namespace App\Support\SaasBilling;

use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\SubscriptionStatus;
use App\Models\AccountSubscriptionPayment;
use App\Models\AccountSubscriptionPaymentMethod;
use App\Models\IntegrationSetting;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;

class ChargeSubscriptionAfterVerification
{
    public function __construct(
        private readonly CreateBillingV2Payment $createPayment,
        private readonly ChargeAccountSubscription $chargeSubscription,
    ) {}

    public function execute(
        PaymentCallbackResult $callback,
        IntegrationSetting $setting,
    ): ?AccountSubscriptionPayment {
        if (! config('ladna.saas_billing_v2_enabled')) {
            return null;
        }

        if ($callback->status !== PaymentCallbackStatus::Paid) {
            return null;
        }

        $references = array_values(array_filter([
            $callback->orderId !== '' ? $callback->orderId : null,
            $callback->gatewayInvoiceId,
        ]));

        if ($references === []) {
            return null;
        }

        $paymentMethod = AccountSubscriptionPaymentMethod::query()
            ->where(function ($query) use ($references): void {
                $query
                    ->whereIn('verification_reference', $references)
                    ->orWhereIn('verification_invoice_id', $references);
            })
            ->with(['subscription.account.locations', 'subscription.plan', 'subscription.priceVersion'])
            ->first();
        $subscription = $paymentMethod?->subscription;

        if (! $paymentMethod?->isActive() || ! $subscription?->usesLocationBilling()) {
            return null;
        }

        if ($subscription->status === SubscriptionStatus::Active) {
            return null;
        }

        if ($subscription->status === SubscriptionStatus::Trialing && $subscription->trial_ends_at?->isFuture()) {
            return null;
        }

        if ($subscription->next_payment_at?->isFuture()) {
            return null;
        }

        $paymentType = $subscription->status === SubscriptionStatus::PastDue
            ? AccountSubscriptionPaymentType::AutoRenewal
            : AccountSubscriptionPaymentType::FullSubscription;
        $attempt = $paymentType === AccountSubscriptionPaymentType::AutoRenewal
            ? max(1, (int) $subscription->renewal_attempts + 1)
            : 0;
        $payment = $this->createPayment->execute(
            $subscription,
            $paymentType,
            renewalAttempt: $attempt,
        );

        $this->chargeSubscription->execute(
            $payment,
            $setting,
            route('dashboard.accounts.tariff-payments.show', $subscription->account),
            true,
        );

        return $payment->refresh();
    }
}

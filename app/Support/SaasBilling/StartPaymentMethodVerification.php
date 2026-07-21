<?php

namespace App\Support\SaasBilling;

use App\Enums\IntegrationProvider;
use App\Enums\SubscriptionBillingInterval;
use App\Enums\SubscriptionPaymentMethodStatus;
use App\Models\AccountSubscription;
use App\Models\IntegrationSetting;
use App\Support\Payments\PaymentCheckout;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class StartPaymentMethodVerification
{
    public function __construct(private readonly MonopayTokenizedBilling $billing) {}

    public function execute(
        AccountSubscription $subscription,
        SubscriptionBillingInterval $interval,
        IntegrationSetting $setting,
        string $redirectUrl,
    ): PaymentCheckout {
        if (! config('ladna.saas_billing_v2_enabled')) {
            throw new LogicException('Ladna billing v2 is disabled.');
        }

        $lock = Cache::lock('saas-billing-v2:verification:'.$subscription->getKey(), 60);

        if (! $lock->get()) {
            throw new LogicException('Card verification is already being started.');
        }

        try {
            return $this->executeLocked($subscription, $interval, $setting, $redirectUrl);
        } finally {
            $lock->release();
        }
    }

    private function executeLocked(
        AccountSubscription $subscription,
        SubscriptionBillingInterval $interval,
        IntegrationSetting $setting,
        string $redirectUrl,
    ): PaymentCheckout {
        $subscription->refresh();

        if (! $subscription->usesLocationBilling()) {
            throw new LogicException('Tokenized billing is available only for billing-v2 subscriptions.');
        }

        $paymentMethod = $subscription->paymentMethod()->firstOrNew();

        if ($paymentMethod->isActive()) {
            throw new LogicException('The payment method is already verified.');
        }

        if ($paymentMethod->status === SubscriptionPaymentMethodStatus::PendingVerification && $paymentMethod->verification_invoice_id) {
            throw new LogicException('Card verification is already in progress.');
        }

        $paymentMethod->forceFill([
            'account_id' => $subscription->account_id,
            'provider' => IntegrationProvider::Monopay->value,
            'provider_wallet_id' => $paymentMethod->provider_wallet_id ?: Str::lower((string) Str::uuid()),
            'status' => SubscriptionPaymentMethodStatus::PendingVerification,
            'verification_reference' => 'SAAS-VERIFY-'.Str::upper(Str::random(24)),
            'verification_invoice_id' => null,
            'last_callback_payload' => null,
            'verified_at' => null,
            'revoked_at' => null,
        ])->save();

        $subscription->forceFill([
            'billing_interval_v2' => $interval,
            'payment_provider' => IntegrationProvider::Monopay->value,
            'auto_renew_enabled' => true,
            'next_payment_at' => $subscription->trial_ends_at?->isFuture()
                ? $subscription->trial_ends_at
                : now(),
            'cancel_at_period_end' => false,
            'cancellation_requested_at' => null,
            'cancelled_at' => null,
        ])->save();

        try {
            $checkout = $this->billing->startVerification($paymentMethod, $setting, $redirectUrl);
        } catch (Throwable $exception) {
            $paymentMethod->forceFill([
                'status' => SubscriptionPaymentMethodStatus::Failed,
            ])->save();

            throw $exception;
        }

        $paymentMethod->forceFill([
            'verification_invoice_id' => $checkout->gatewayPayload['response']['invoiceId'] ?? null,
        ])->save();

        return $checkout;
    }
}

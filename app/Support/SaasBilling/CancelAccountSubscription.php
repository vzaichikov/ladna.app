<?php

namespace App\Support\SaasBilling;

use App\Enums\SubscriptionPaymentMethodStatus;
use App\Enums\SubscriptionStatus;
use App\Models\AccountSubscription;
use LogicException;
use Throwable;

class CancelAccountSubscription
{
    public function __construct(
        private readonly MonopaySaasBilling $legacyBilling,
        private readonly MonopayTokenizedBilling $tokenizedBilling,
        private readonly SendBillingLifecycleNotification $notifications,
    ) {}

    public function request(AccountSubscription $subscription): AccountSubscription
    {
        if (! $subscription->usesLocationBilling() || ! $subscription->ends_at?->isFuture()) {
            throw new LogicException('Only a current billing-v2 subscription may be cancelled.');
        }

        $subscription->forceFill([
            'cancel_at_period_end' => true,
            'cancellation_requested_at' => now(),
            'auto_renew_enabled' => false,
            'next_payment_at' => null,
        ])->save();

        $subscription->loadMissing('account');
        $this->notifications->execute(
            $subscription,
            'cancellation',
            $subscription->ends_at,
            ['date' => $subscription->ends_at->timezone($subscription->account?->timezone ?? config('app.timezone'))->format('d.m.Y')],
        );

        return $subscription->refresh();
    }

    public function resume(AccountSubscription $subscription): AccountSubscription
    {
        $subscription->loadMissing('paymentMethod');

        if (! $subscription->usesLocationBilling() || ! $subscription->cancel_at_period_end || ! $subscription->ends_at?->isFuture()) {
            throw new LogicException('This subscription cannot be resumed.');
        }

        if (! $subscription->paymentMethod?->isActive()) {
            throw new LogicException('Verify a payment method before resuming automatic renewal.');
        }

        $subscription->forceFill([
            'cancel_at_period_end' => false,
            'cancellation_requested_at' => null,
            'auto_renew_enabled' => true,
            'next_payment_at' => $subscription->ends_at,
            'cancelled_at' => null,
        ])->save();

        $subscription->loadMissing('account');
        $this->notifications->execute(
            $subscription,
            'reactivation',
            $subscription->ends_at,
            ['date' => $subscription->ends_at->timezone($subscription->account?->timezone ?? config('app.timezone'))->format('d.m.Y')],
        );

        return $subscription->refresh();
    }

    public function finalize(AccountSubscription $subscription): AccountSubscription
    {
        $subscription->loadMissing('paymentMethod');
        $paymentMethod = $subscription->paymentMethod;
        $setting = $this->legacyBilling->platformSetting();

        if ($paymentMethod && $setting) {
            try {
                $this->tokenizedBilling->revokeCard($paymentMethod, $setting);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($paymentMethod) {
            $paymentMethod->forceFill([
                'provider_wallet_id' => '',
                'provider_card_token' => null,
                'masked_pan' => null,
                'card_brand' => null,
                'status' => SubscriptionPaymentMethodStatus::Revoked,
                'revoked_at' => now(),
            ])->save();
        }

        $subscription->forceFill([
            'status' => SubscriptionStatus::Cancelled,
            'auto_renew_enabled' => false,
            'cancel_at_period_end' => false,
            'next_payment_at' => null,
            'next_retry_at' => null,
            'grace_ends_at' => null,
            'pending_subscription_price_version_id' => null,
            'pending_tariff_change_at' => null,
            'cancelled_at' => now(),
        ])->save();

        return $subscription->refresh();
    }
}

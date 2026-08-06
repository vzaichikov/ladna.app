<?php

namespace App\Support\Sms;

use App\Enums\AccountPaymentMethodVerificationPurpose;
use App\Enums\IntegrationProvider;
use App\Enums\SubscriptionPaymentMethodStatus;
use App\Models\Account;
use App\Models\IntegrationSetting;
use App\Support\Payments\PaymentCheckout;
use App\Support\SaasBilling\MonopayTokenizedBilling;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class StartSmsPaymentMethodVerification
{
    public function __construct(private readonly MonopayTokenizedBilling $billing) {}

    public function execute(
        Account $account,
        int $topUpAmountCents,
        IntegrationSetting $setting,
        string $redirectUrl,
    ): PaymentCheckout {
        if ($account->isReadOnlyDemo()) {
            throw new LogicException('Read-only demo accounts cannot save a payment card for SMS credit.');
        }

        $subscription = $account->subscription()->first();

        if (! $subscription) {
            throw new LogicException('An account subscription is required to save a payment card.');
        }

        $lock = Cache::lock('account-payment-method-verification:'.$account->id, 120);

        if (! $lock->get()) {
            throw new LogicException('Card verification is already being started.');
        }

        try {
            $paymentMethod = $subscription->paymentMethod()->firstOrNew();

            if ($paymentMethod->isActive()) {
                throw new LogicException('The payment method is already verified.');
            }

            if (
                $paymentMethod->status === SubscriptionPaymentMethodStatus::PendingVerification
                && $paymentMethod->verification_invoice_id
            ) {
                throw new LogicException('Card verification is already in progress.');
            }

            $paymentMethod->forceFill([
                'account_id' => $account->id,
                'provider' => IntegrationProvider::Monopay->value,
                'provider_wallet_id' => $paymentMethod->provider_wallet_id ?: Str::lower((string) Str::uuid()),
                'status' => SubscriptionPaymentMethodStatus::PendingVerification,
                'verification_reference' => 'SMS-VERIFY-'.Str::upper(Str::random(24)),
                'verification_invoice_id' => null,
                'verification_purpose' => AccountPaymentMethodVerificationPurpose::SmsTopUp,
                'verification_amount_cents' => $topUpAmountCents,
                'last_callback_payload' => null,
                'verified_at' => null,
                'revoked_at' => null,
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
        } finally {
            $lock->release();
        }
    }
}

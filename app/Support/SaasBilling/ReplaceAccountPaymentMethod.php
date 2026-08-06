<?php

namespace App\Support\SaasBilling;

use App\Enums\AccountPaymentMethodVerificationPurpose;
use App\Enums\IntegrationProvider;
use App\Enums\SubscriptionPaymentMethodStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\AccountSubscriptionPaymentMethod;
use App\Models\IntegrationSetting;
use App\Support\Payments\PaymentCheckout;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class ReplaceAccountPaymentMethod
{
    public const IN_PROGRESS = 'Payment method replacement is already being started.';

    public function __construct(private readonly MonopayTokenizedBilling $billing) {}

    public function execute(
        Account $account,
        IntegrationSetting $setting,
        string $redirectUrl,
    ): PaymentCheckout {
        if ($account->isReadOnlyDemo()) {
            throw new LogicException('Read-only demo accounts cannot change the payment method.');
        }

        $lock = Cache::lock('account-payment-method-verification:'.$account->getKey(), 120);

        if (! $lock->get()) {
            throw new LogicException(self::IN_PROGRESS);
        }

        try {
            return $this->executeLocked($account, $setting, $redirectUrl);
        } finally {
            $lock->release();
        }
    }

    private function executeLocked(
        Account $account,
        IntegrationSetting $setting,
        string $redirectUrl,
    ): PaymentCheckout {
        $paymentMethod = DB::transaction(function () use ($account, $setting): AccountSubscriptionPaymentMethod {
            $subscription = $account->subscription()->lockForUpdate()->first();

            if (! $subscription) {
                throw new LogicException('An account subscription is required to save a payment card.');
            }

            $paymentMethod = $subscription->paymentMethod()->lockForUpdate()->first()
                ?? $subscription->paymentMethod()->make(['account_id' => $account->getKey()]);

            if (
                $paymentMethod->status === SubscriptionPaymentMethodStatus::PendingVerification
                && filled($paymentMethod->verification_invoice_id)
                && $paymentMethod->updated_at?->isAfter(now()->subSeconds($this->verificationValiditySeconds($setting)))
            ) {
                throw new LogicException(self::IN_PROGRESS);
            }

            $hasProviderToken = filled($paymentMethod->provider_card_token);

            if ($hasProviderToken) {
                $this->billing->revokeCard($paymentMethod, $setting);
            }

            $this->prepareNewVerification($account, $subscription, $paymentMethod, $hasProviderToken);

            return $paymentMethod;
        });

        try {
            $checkout = $this->billing->startVerification($paymentMethod, $setting, $redirectUrl);
        } catch (Throwable $exception) {
            AccountSubscriptionPaymentMethod::query()
                ->whereKey($paymentMethod->getKey())
                ->where('verification_reference', $paymentMethod->verification_reference)
                ->where('status', SubscriptionPaymentMethodStatus::PendingVerification->value)
                ->update(['status' => SubscriptionPaymentMethodStatus::Failed->value]);

            throw $exception;
        }

        AccountSubscriptionPaymentMethod::query()
            ->whereKey($paymentMethod->getKey())
            ->where('verification_reference', $paymentMethod->verification_reference)
            ->where('status', SubscriptionPaymentMethodStatus::PendingVerification->value)
            ->update([
                'verification_invoice_id' => $checkout->gatewayPayload['response']['invoiceId'] ?? null,
            ]);

        return $checkout;
    }

    private function prepareNewVerification(
        Account $account,
        AccountSubscription $subscription,
        AccountSubscriptionPaymentMethod $paymentMethod,
        bool $hasProviderToken,
    ): void {
        $paymentMethod->forceFill([
            'account_id' => $account->getKey(),
            'provider' => IntegrationProvider::Monopay->value,
            'provider_wallet_id' => Str::lower((string) Str::uuid()),
            'provider_card_token' => null,
            'masked_pan' => null,
            'card_brand' => null,
            'status' => SubscriptionPaymentMethodStatus::PendingVerification,
            'verification_reference' => 'PAYMENT-METHOD-VERIFY-'.Str::upper(Str::random(24)),
            'verification_invoice_id' => null,
            'verification_purpose' => AccountPaymentMethodVerificationPurpose::PaymentMethodChange,
            'verification_amount_cents' => null,
            'last_callback_payload' => null,
            'verified_at' => null,
            'revoked_at' => $hasProviderToken ? now() : $paymentMethod->revoked_at,
        ])->save();

        if ($subscription->status === SubscriptionStatus::PastDue && $subscription->next_retry_at !== null) {
            $subscription->forceFill(['next_retry_at' => null])->save();
        }

        $account->smsWallet()
            ->where('auto_top_up_enabled', true)
            ->update(['auto_top_up_suspended_at' => now()]);
    }

    private function verificationValiditySeconds(IntegrationSetting $setting): int
    {
        $configuredValidity = (int) ($setting->readableCredentials()['invoice_validity_seconds'] ?? 3600);

        return min(86_400, max(60, $configuredValidity));
    }
}

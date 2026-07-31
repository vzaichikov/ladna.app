<?php

namespace App\Support\Sms;

use App\Enums\AccountPaymentMethodVerificationPurpose;
use App\Enums\SmsTopUpKind;
use App\Models\AccountSubscriptionPayment;
use App\Models\AccountSubscriptionPaymentMethod;
use App\Models\IntegrationSetting;
use App\Models\SmsTopUpPayment;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use App\Support\SaasBilling\ChargeSubscriptionAfterVerification;

class ResumeSmsPaymentAfterVerification
{
    public function __construct(
        private readonly ChargeSubscriptionAfterVerification $chargeSubscription,
        private readonly CreateSmsTopUpPayment $createTopUp,
        private readonly ChargeSmsTopUpPayment $chargeTopUp,
    ) {}

    public function execute(
        PaymentCallbackResult $callback,
        IntegrationSetting $setting,
    ): AccountSubscriptionPayment|SmsTopUpPayment|null {
        if ($callback->status !== PaymentCallbackStatus::Paid) {
            return null;
        }

        $paymentMethod = $this->paymentMethod($callback);

        if (! $paymentMethod?->isActive()) {
            return null;
        }

        if ($paymentMethod->verification_purpose === AccountPaymentMethodVerificationPurpose::Subscription) {
            return $this->chargeSubscription->execute($callback, $setting);
        }

        $idempotencyKey = 'sms-top-up-after-verification:'.$paymentMethod->verification_reference;
        $existing = SmsTopUpPayment::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            return $existing;
        }

        $amountCents = (int) $paymentMethod->verification_amount_cents;

        if ($amountCents <= 0) {
            return null;
        }

        $payment = $this->createTopUp->execute(
            account: $paymentMethod->account,
            amountCents: $amountCents,
            kind: SmsTopUpKind::Manual,
            idempotencyKey: $idempotencyKey,
        );
        $paymentMethod->forceFill(['verification_amount_cents' => null])->save();

        $this->chargeTopUp->execute(
            payment: $payment,
            setting: $setting,
            redirectUrl: route('dashboard.accounts.sms-account.show', $paymentMethod->account),
            ownerInitiated: true,
        );

        return $payment->refresh();
    }

    private function paymentMethod(PaymentCallbackResult $callback): ?AccountSubscriptionPaymentMethod
    {
        $references = array_values(array_filter([
            $callback->orderId !== '' ? $callback->orderId : null,
            $callback->gatewayInvoiceId,
        ]));

        if ($references === []) {
            return null;
        }

        return AccountSubscriptionPaymentMethod::query()
            ->where(function ($query) use ($references): void {
                $query
                    ->whereIn('verification_reference', $references)
                    ->orWhereIn('verification_invoice_id', $references);
            })
            ->with('account')
            ->first();
    }
}

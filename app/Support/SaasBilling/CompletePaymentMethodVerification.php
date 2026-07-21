<?php

namespace App\Support\SaasBilling;

use App\Enums\SubscriptionPaymentMethodStatus;
use App\Models\AccountSubscriptionPaymentMethod;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use Illuminate\Support\Facades\DB;

class CompletePaymentMethodVerification
{
    public function execute(PaymentCallbackResult $callback): bool
    {
        return DB::transaction(fn (): bool => $this->complete($callback));
    }

    private function complete(PaymentCallbackResult $callback): bool
    {
        $references = array_values(array_filter([
            $callback->orderId !== '' ? $callback->orderId : null,
            $callback->gatewayInvoiceId,
        ]));

        if ($references === []) {
            return false;
        }

        $paymentMethod = AccountSubscriptionPaymentMethod::query()
            ->where(function ($query) use ($references): void {
                $query
                    ->whereIn('verification_reference', $references)
                    ->orWhereIn('verification_invoice_id', $references);
            })
            ->lockForUpdate()
            ->first();

        if (! $paymentMethod) {
            return false;
        }

        if ($callback->amountCents !== null && $callback->amountCents !== 0) {
            throw new InvalidPaymentCallbackException('Card verification callback amount must be zero.');
        }

        if ($callback->currency !== null && strtoupper($callback->currency) !== 'UAH') {
            throw new InvalidPaymentCallbackException('Card verification callback currency is invalid.');
        }

        if ($paymentMethod->isActive()) {
            return true;
        }

        $paymentMethod->forceFill([
            'verification_invoice_id' => $callback->gatewayInvoiceId ?? $paymentMethod->verification_invoice_id,
            'last_callback_payload' => $callback->payload,
        ]);

        if ($callback->status === PaymentCallbackStatus::Paid) {
            $walletData = is_array($callback->payload['walletData'] ?? null)
                ? $callback->payload['walletData']
                : [];
            $cardToken = $walletData['cardToken'] ?? null;
            $walletId = $walletData['walletId'] ?? null;

            if (! is_string($cardToken) || $cardToken === '' || ! is_string($walletId) || $walletId === '') {
                throw new InvalidPaymentCallbackException('Monopay did not return tokenized card data.');
            }

            if (! hash_equals((string) $paymentMethod->provider_wallet_id, $walletId)) {
                throw new InvalidPaymentCallbackException('Card verification wallet does not match the subscription.');
            }

            $paymentInfo = is_array($callback->payload['paymentInfo'] ?? null)
                ? $callback->payload['paymentInfo']
                : [];

            $paymentMethod->forceFill([
                'provider_card_token' => $cardToken,
                'masked_pan' => is_string($paymentInfo['maskedPan'] ?? null) ? $paymentInfo['maskedPan'] : null,
                'card_brand' => is_string($paymentInfo['paymentSystem'] ?? null) ? $paymentInfo['paymentSystem'] : null,
                'status' => SubscriptionPaymentMethodStatus::Active,
                'verified_at' => now(),
                'revoked_at' => null,
            ]);
        } elseif ($callback->status !== PaymentCallbackStatus::Pending) {
            $paymentMethod->forceFill([
                'status' => SubscriptionPaymentMethodStatus::Failed,
            ]);
        }

        $paymentMethod->save();

        return true;
    }
}

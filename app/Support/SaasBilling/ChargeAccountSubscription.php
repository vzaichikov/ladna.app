<?php

namespace App\Support\SaasBilling;

use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\AccountSubscriptionPayment;
use App\Models\IntegrationSetting;
use App\Support\Payments\PaymentAmounts;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use LogicException;

class ChargeAccountSubscription
{
    public function __construct(
        private readonly MonopayTokenizedBilling $billing,
        private readonly CompleteAccountSubscriptionPayment $completePayment,
    ) {}

    public function execute(
        AccountSubscriptionPayment $payment,
        IntegrationSetting $setting,
        string $redirectUrl,
        bool $ownerInitiated = false,
    ): ?string {
        if (! config('ladna.saas_billing_v2_enabled')) {
            throw new LogicException('Ladna billing v2 is disabled.');
        }

        $lock = Cache::lock('saas-billing-v2:payment:'.$payment->getKey(), 60);

        if (! $lock->get()) {
            return $payment->refresh()->checkoutUrl();
        }

        try {
            return $this->executeLocked($payment, $setting, $redirectUrl, $ownerInitiated);
        } finally {
            $lock->release();
        }
    }

    private function executeLocked(
        AccountSubscriptionPayment $payment,
        IntegrationSetting $setting,
        string $redirectUrl,
        bool $ownerInitiated,
    ): ?string {
        $payment->refresh();
        $payment->loadMissing(['subscription.paymentMethod', 'plan']);

        if ($payment->status === AccountSubscriptionPaymentStatus::PaymentPaid) {
            return null;
        }

        if ($payment->gateway_invoice_id) {
            $response = is_array($payment->gateway_checkout_payload['response'] ?? null)
                ? $payment->gateway_checkout_payload['response']
                : [];

            return is_string($response['pageUrl'] ?? null) ? $response['pageUrl'] : null;
        }

        $paymentMethod = $payment->subscription?->paymentMethod;

        if (! $paymentMethod?->isActive()) {
            throw new LogicException('A verified payment method is required.');
        }

        $gatewayPayload = $this->billing->charge(
            $payment,
            $paymentMethod,
            $setting,
            $redirectUrl,
            $ownerInitiated,
        );
        $response = $gatewayPayload['response'];
        $status = (string) ($response['status'] ?? 'processing');
        $gatewayInvoiceId = (string) $response['invoiceId'];
        $pageUrl = is_string($response['pageUrl'] ?? null) ? $response['pageUrl'] : null;

        $payment->forceFill([
            'gateway_invoice_id' => $gatewayInvoiceId,
            'gateway_payment_id' => is_string($response['paymentId'] ?? null) ? $response['paymentId'] : null,
            'gateway_status' => $status,
            'status' => AccountSubscriptionPaymentStatus::PaymentPending,
            'gateway_checkout_payload' => [
                'request' => $this->sanitize($gatewayPayload['request']),
                'response' => $response,
            ],
        ])->save();

        if (! $ownerInitiated && ! in_array($status, ['success', 'failure', 'reversed', 'cancelled'], true)) {
            $payment->subscription?->forceFill([
                'status' => SubscriptionStatus::PastDue,
                'provider_status' => $pageUrl ? 'owner_interaction_required' : $status,
                'grace_ends_at' => $payment->subscription->grace_ends_at ?? now()->addDays(7),
                'renewal_attempts' => max((int) $payment->subscription->renewal_attempts, (int) $payment->renewal_attempt),
                'next_retry_at' => null,
            ])->save();
        }

        if (in_array($status, ['success', 'failure', 'reversed', 'cancelled'], true)) {
            $callbackStatus = match ($status) {
                'success' => PaymentCallbackStatus::Paid,
                'failure' => PaymentCallbackStatus::Failed,
                default => PaymentCallbackStatus::Cancelled,
            };

            $this->completePayment->execute($payment, new PaymentCallbackResult(
                orderId: $payment->order_id,
                status: $callbackStatus,
                gatewayStatus: $status,
                amountCents: isset($response['finalAmount']) ? (int) $response['finalAmount'] : $payment->amount_cents,
                currency: isset($response['ccy']) ? PaymentAmounts::currencyFromIso4217($response['ccy']) : $payment->currency,
                gatewayInvoiceId: $gatewayInvoiceId,
                gatewayPaymentId: is_string($response['paymentId'] ?? null) ? $response['paymentId'] : null,
                failureReason: is_string($response['failureReason'] ?? null) ? $response['failureReason'] : null,
                paidAt: is_string($response['modifiedDate'] ?? null) ? Carbon::parse($response['modifiedDate']) : null,
                payload: $response,
            ));
        }

        return $pageUrl;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitize(array $payload): array
    {
        if (array_key_exists('cardToken', $payload)) {
            $payload['cardToken'] = '[REDACTED]';
        }

        return $payload;
    }
}

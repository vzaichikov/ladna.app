<?php

namespace App\Support\Sms;

use App\Enums\SmsTopUpPaymentStatus;
use App\Models\IntegrationSetting;
use App\Models\SmsTopUpPayment;
use App\Support\Payments\PaymentAmounts;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use App\Support\SaasBilling\MonopayTokenizedBilling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use LogicException;

class ChargeSmsTopUpPayment
{
    public function __construct(
        private readonly MonopayTokenizedBilling $billing,
        private readonly CompleteSmsTopUpPayment $completePayment,
    ) {}

    public function execute(
        SmsTopUpPayment $payment,
        IntegrationSetting $setting,
        string $redirectUrl,
        bool $ownerInitiated,
    ): ?string {
        $lock = Cache::lock('sms-top-up-payment:'.$payment->getKey(), 60);

        if (! $lock->get()) {
            return $this->checkoutUrl($payment->refresh());
        }

        try {
            return $this->executeLocked($payment, $setting, $redirectUrl, $ownerInitiated);
        } finally {
            $lock->release();
        }
    }

    private function executeLocked(
        SmsTopUpPayment $payment,
        IntegrationSetting $setting,
        string $redirectUrl,
        bool $ownerInitiated,
    ): ?string {
        $payment->refresh();
        $payment->loadMissing('paymentMethod');

        if ($payment->status === SmsTopUpPaymentStatus::PaymentPaid) {
            return null;
        }

        if ($payment->gateway_invoice_id) {
            return $this->checkoutUrl($payment);
        }

        if (! $payment->paymentMethod?->isActive()) {
            throw new LogicException('A verified payment method is required.');
        }

        $gatewayPayload = $this->billing->charge(
            $payment,
            $payment->paymentMethod,
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
            'status' => SmsTopUpPaymentStatus::PaymentPending,
            'gateway_checkout_payload' => [
                'request' => $this->sanitize($gatewayPayload['request']),
                'response' => $response,
            ],
        ])->save();

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

    private function checkoutUrl(SmsTopUpPayment $payment): ?string
    {
        $response = is_array($payment->gateway_checkout_payload['response'] ?? null)
            ? $payment->gateway_checkout_payload['response']
            : [];
        $pageUrl = $response['pageUrl'] ?? null;

        return is_string($pageUrl) && $pageUrl !== '' ? $pageUrl : null;
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

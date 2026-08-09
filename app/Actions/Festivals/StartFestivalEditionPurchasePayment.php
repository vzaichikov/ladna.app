<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalEditionPurchaseStatus;
use App\Models\FestivalEditionPurchase;
use App\Support\Payments\PaymentAmounts;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use App\Support\Payments\PaymentGatewayException;
use App\Support\SaasBilling\MonopaySaasBilling;
use App\Support\SaasBilling\MonopayTokenizedBilling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class StartFestivalEditionPurchasePayment
{
    public function __construct(
        private readonly MonopaySaasBilling $hostedBilling,
        private readonly MonopayTokenizedBilling $tokenizedBilling,
        private readonly CompleteFestivalEditionPurchase $complete,
    ) {}

    public function execute(FestivalEditionPurchase $purchase, string $redirectUrl): ?string
    {
        if ($purchase->amount_cents === 0 || $purchase->status === FestivalEditionPurchaseStatus::Available) {
            return null;
        }

        $setting = $this->hostedBilling->platformSetting();
        if (! $setting) {
            throw new PaymentGatewayException('Platform payment integration is unavailable.');
        }

        $lock = Cache::lock('festival-edition-purchase:'.$purchase->id, 60);
        if (! $lock->get()) {
            return $purchase->refresh()->checkoutUrl();
        }

        try {
            $purchase->refresh()->loadMissing('paymentMethod');
            if ($purchase->gateway_invoice_id) {
                return $purchase->checkoutUrl();
            }

            if ($purchase->paymentMethod?->isActive()) {
                $gatewayPayload = $this->tokenizedBilling->charge($purchase, $purchase->paymentMethod, $setting, $redirectUrl, true);
                $response = $gatewayPayload['response'];
                $url = is_string($response['pageUrl'] ?? null) ? $response['pageUrl'] : null;
                $this->persistGatewayStart($purchase, $gatewayPayload, $response);
                $this->completeInlineStatus($purchase, $response);

                return $url;
            }

            $checkout = $this->hostedBilling->startOneTimePayment($purchase, $setting, $redirectUrl);
            $response = is_array($checkout->gatewayPayload['response'] ?? null) ? $checkout->gatewayPayload['response'] : [];
            $this->persistGatewayStart($purchase, $checkout->gatewayPayload, $response);

            return $checkout->url;
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, mixed> $gatewayPayload @param array<string, mixed> $response */
    private function persistGatewayStart(FestivalEditionPurchase $purchase, array $gatewayPayload, array $response): void
    {
        $request = is_array($gatewayPayload['request'] ?? null) ? $gatewayPayload['request'] : [];
        if (array_key_exists('cardToken', $request)) {
            $request['cardToken'] = '[REDACTED]';
        }

        $purchase->forceFill([
            'gateway_invoice_id' => is_string($response['invoiceId'] ?? null) ? $response['invoiceId'] : $purchase->gateway_invoice_id,
            'gateway_payment_id' => is_string($response['paymentId'] ?? null) ? $response['paymentId'] : null,
            'gateway_status' => (string) ($response['status'] ?? 'created'),
            'status' => FestivalEditionPurchaseStatus::PaymentPending,
            'gateway_checkout_payload' => ['request' => $request, 'response' => $response],
        ])->save();
    }

    /** @param array<string, mixed> $response */
    private function completeInlineStatus(FestivalEditionPurchase $purchase, array $response): void
    {
        $status = (string) ($response['status'] ?? 'processing');
        if (! in_array($status, ['success', 'failure', 'expired', 'reversed', 'refunded', 'cancelled'], true)) {
            return;
        }

        $callbackStatus = match ($status) {
            'success' => PaymentCallbackStatus::Paid,
            'failure' => PaymentCallbackStatus::Failed,
            'expired' => PaymentCallbackStatus::Expired,
            default => PaymentCallbackStatus::Cancelled,
        };
        $this->complete->execute($purchase, new PaymentCallbackResult(
            orderId: (string) $purchase->order_id,
            status: $callbackStatus,
            gatewayStatus: $status,
            amountCents: isset($response['finalAmount']) ? (int) $response['finalAmount'] : $purchase->amount_cents,
            currency: isset($response['ccy']) ? PaymentAmounts::currencyFromIso4217($response['ccy']) : $purchase->currency,
            gatewayInvoiceId: is_string($response['invoiceId'] ?? null) ? $response['invoiceId'] : null,
            gatewayPaymentId: is_string($response['paymentId'] ?? null) ? $response['paymentId'] : null,
            failureReason: is_string($response['failureReason'] ?? null) ? $response['failureReason'] : null,
            paidAt: is_string($response['modifiedDate'] ?? null) ? Carbon::parse($response['modifiedDate']) : null,
            payload: $response,
        ));
    }
}

<?php

namespace App\Support\SaasBilling;

use App\Models\AccountSubscriptionPayment;
use App\Models\IntegrationSetting;
use App\Support\Payments\PaymentCheckout;

class StartAccountSubscriptionPaymentCheckout
{
    public function __construct(private readonly MonopaySaasBilling $billing) {}

    public function execute(
        AccountSubscriptionPayment $payment,
        IntegrationSetting $setting,
        string $redirectUrl,
    ): PaymentCheckout {
        $payment->loadMissing(['plan', 'signupRequest']);

        $checkout = $payment->plan?->requires_recurring_payment
            ? $this->billing->startRecurringPayment($payment, $setting, $redirectUrl)
            : $this->billing->startOneTimePayment($payment, $setting, $redirectUrl);

        $payload = $checkout->gatewayPayload;
        $response = is_array($payload['response'] ?? null) ? $payload['response'] : [];

        $payment->forceFill([
            'gateway_invoice_id' => is_string($response['invoiceId'] ?? null) ? $response['invoiceId'] : null,
            'gateway_subscription_id' => is_string($response['subscriptionId'] ?? null) ? $response['subscriptionId'] : null,
            'gateway_status' => is_string($response['status'] ?? null) ? $response['status'] : null,
            'gateway_checkout_payload' => $payload,
        ])->save();

        if ($payment->signupRequest) {
            $payment->signupRequest->forceFill([
                'gateway_invoice_id' => $payment->gateway_invoice_id,
                'gateway_status' => $payment->gateway_status,
                'gateway_checkout_payload' => $payload,
            ])->save();
        }

        return $checkout;
    }
}

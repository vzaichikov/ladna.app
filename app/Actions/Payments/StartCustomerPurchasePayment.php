<?php

namespace App\Actions\Payments;

use App\Models\CustomerPurchase;
use App\Models\IntegrationSetting;
use App\Support\Payments\PaymentCheckout;
use App\Support\Payments\PaymentCheckoutRequest;
use App\Support\Payments\PaymentGatewayRegistry;

class StartCustomerPurchasePayment
{
    public function __construct(private readonly PaymentGatewayRegistry $gateways) {}

    public function execute(
        CustomerPurchase $purchase,
        IntegrationSetting $setting,
        ?string $returnUrl = null,
    ): PaymentCheckout {
        $gateway = $this->gateways->get($purchase->provider);
        $purchase->loadMissing(['account', 'customer']);
        $checkout = $gateway->start(new PaymentCheckoutRequest(
            reference: $purchase->order_id,
            amountCents: $purchase->amount_cents,
            currency: $purchase->currency,
            description: $purchase->plan_name,
            buyerName: $purchase->customer->name,
            buyerEmail: $purchase->customer->email,
            buyerPhone: $purchase->customer->phone,
            locale: app()->getLocale(),
            returnUrl: $returnUrl ?? route('customer.purchases.return', [$purchase->account->slug, $purchase]),
            callbackUrl: route('api.v1.payments.callbacks', $gateway->provider()->value),
            expiresAt: $purchase->expires_at ?? now()->addHour(),
        ), $setting);

        $payload = $checkout->gatewayPayload;
        $response = is_array($payload['response'] ?? null) ? $payload['response'] : [];

        $purchase->forceFill([
            'gateway_checkout_payload' => $payload,
            'gateway_invoice_id' => $response['invoiceId'] ?? $purchase->gateway_invoice_id,
            'gateway_status' => $response['status'] ?? $purchase->gateway_status,
        ])->save();

        return $checkout;
    }
}

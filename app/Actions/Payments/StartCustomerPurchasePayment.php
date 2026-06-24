<?php

namespace App\Actions\Payments;

use App\Models\CustomerPurchase;
use App\Models\IntegrationSetting;
use App\Support\Payments\PaymentCheckout;
use App\Support\Payments\PaymentGatewayRegistry;

class StartCustomerPurchasePayment
{
    public function __construct(private readonly PaymentGatewayRegistry $gateways) {}

    public function execute(CustomerPurchase $purchase, IntegrationSetting $setting): PaymentCheckout
    {
        $gateway = $this->gateways->get($purchase->provider);
        $checkout = $gateway->start($purchase->loadMissing(['account', 'customer']), $setting);

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

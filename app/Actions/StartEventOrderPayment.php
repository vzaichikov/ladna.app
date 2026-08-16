<?php

namespace App\Actions;

use App\Enums\IntegrationProvider;
use App\Models\EventOrder;
use App\Models\IntegrationSetting;
use App\Support\Payments\MonopayCheckoutSettings;
use App\Support\Payments\PaymentCheckout;
use App\Support\Payments\PaymentCheckoutRequest;
use App\Support\Payments\PaymentGatewayException;
use App\Support\Payments\PaymentGatewayRegistry;

class StartEventOrderPayment
{
    public function __construct(
        private readonly PaymentGatewayRegistry $gateways,
        private readonly MonopayCheckoutSettings $monopayCheckoutSettings,
    ) {}

    public function execute(EventOrder $order, IntegrationSetting $setting): PaymentCheckout
    {
        $order->loadMissing(['account', 'event']);

        if ($setting->account_id !== $order->account_id || $setting->provider->value !== $order->provider) {
            throw new PaymentGatewayException('Payment integration does not match the event order.');
        }

        $gateway = $this->gateways->get($order->provider);
        $checkout = $gateway->start(new PaymentCheckoutRequest(
            reference: $order->order_id,
            amountCents: $order->amount_cents,
            currency: $order->currency,
            description: $order->event->title,
            buyerName: $order->buyer_name,
            buyerEmail: $order->buyer_email,
            buyerPhone: $order->buyer_phone,
            locale: $order->locale,
            returnUrl: route('public.event-orders.show', [$order->account->slug, $order->access_token_encrypted]),
            callbackUrl: route('api.v1.event-payments.callbacks', $gateway->provider()->value),
            expiresAt: $order->expires_at ?? now()->addMinutes(30),
            preferIframe: $gateway->provider() === IntegrationProvider::Monopay
                && $this->monopayCheckoutSettings->ticketIframeV2Enabled(),
        ), $setting);
        $payload = $checkout->gatewayPayload;
        $response = is_array($payload['response'] ?? null) ? $payload['response'] : [];
        $order->forceFill([
            'gateway_checkout_payload' => $payload,
            'gateway_invoice_id' => $response['invoiceId'] ?? null,
            'gateway_status' => $response['status'] ?? null,
        ])->save();

        return $checkout;
    }
}

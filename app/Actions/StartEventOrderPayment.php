<?php

namespace App\Actions;

use App\Enums\EventOrderStatus;
use App\Enums\IntegrationProvider;
use App\Models\EventOrder;
use App\Models\IntegrationSetting;
use App\Support\Payments\MonopayCheckoutSettings;
use App\Support\Payments\MonopayIframeCompatibility;
use App\Support\Payments\PaymentCheckout;
use App\Support\Payments\PaymentCheckoutRequest;
use App\Support\Payments\PaymentGatewayException;
use App\Support\Payments\PaymentGatewayRegistry;
use App\Support\Payments\TicketPaymentTiming;
use Throwable;

class StartEventOrderPayment
{
    public function __construct(
        private readonly PaymentGatewayRegistry $gateways,
        private readonly MonopayCheckoutSettings $monopayCheckoutSettings,
        private readonly MonopayIframeCompatibility $monopayIframeCompatibility,
        private readonly TicketPaymentTiming $timing,
    ) {}

    public function execute(EventOrder $order, IntegrationSetting $setting, ?string $userAgent = null): PaymentCheckout
    {
        $order->loadMissing(['account', 'event']);

        try {
            if ($setting->account_id !== $order->account_id || $setting->provider->value !== $order->provider) {
                throw new PaymentGatewayException('Payment integration does not match the event order.');
            }

            $gateway = $this->gateways->get($order->provider);
            $timing = $this->timing->resolve($setting);
            $order->forceFill([
                'payment_expires_at' => $timing['payment_expires_at'],
                'expires_at' => $timing['expires_at'],
            ])->save();
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
                expiresAt: $timing['payment_expires_at'],
                preferIframe: $gateway->provider() === IntegrationProvider::Monopay
                    && $this->monopayCheckoutSettings->ticketIframeV2Enabled()
                    && $this->monopayIframeCompatibility->allowsTicketIframe($userAgent),
                validitySeconds: $timing['validity_seconds'],
            ), $setting);
            $payload = [
                ...$checkout->gatewayPayload,
                '_launcher' => [
                    'type' => $checkout->type,
                    'url' => $checkout->url,
                    'method' => $checkout->method,
                    'fields' => $checkout->fields,
                ],
            ];
            $response = is_array($payload['response'] ?? null) ? $payload['response'] : [];
            $order->forceFill([
                'gateway_checkout_payload' => $payload,
                'gateway_invoice_id' => $response['invoiceId'] ?? null,
                'gateway_status' => $response['status'] ?? null,
            ])->save();
        } catch (Throwable $exception) {
            EventOrder::query()
                ->whereKey($order->id)
                ->where('status', EventOrderStatus::Pending->value)
                ->update([
                    'status' => EventOrderStatus::Failed->value,
                    'payment_expires_at' => null,
                    'expires_at' => null,
                    'failure_reason' => $exception->getMessage(),
                    'failed_at' => now(),
                ]);

            throw $exception;
        }

        return $checkout;
    }
}

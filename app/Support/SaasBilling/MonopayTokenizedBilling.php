<?php

namespace App\Support\SaasBilling;

use App\Enums\IntegrationProvider;
use App\Models\AccountSubscriptionPayment;
use App\Models\AccountSubscriptionPaymentMethod;
use App\Models\IntegrationSetting;
use App\Support\Payments\PaymentAmounts;
use App\Support\Payments\PaymentCheckout;
use App\Support\Payments\PaymentGatewayException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class MonopayTokenizedBilling
{
    private const BASE_URL = 'https://api.monobank.ua';

    public function startVerification(
        AccountSubscriptionPaymentMethod $paymentMethod,
        IntegrationSetting $setting,
        string $redirectUrl,
    ): PaymentCheckout {
        $credentials = $setting->readableCredentials();
        $payload = [
            'amount' => 0,
            'ccy' => PaymentAmounts::iso4217NumericCode('UAH'),
            'merchantPaymInfo' => [
                'reference' => $paymentMethod->verification_reference,
                'destination' => 'Ladna payment method verification',
                'comment' => 'Ladna payment method verification',
            ],
            'redirectUrl' => $redirectUrl,
            'webHookUrl' => route('api.v1.saas.payments.callbacks', IntegrationProvider::Monopay->value),
            'validity' => (int) ($credentials['invoice_validity_seconds'] ?? 3600),
            'paymentType' => 'verification',
            'saveCardData' => [
                'saveCard' => true,
                'walletId' => $paymentMethod->provider_wallet_id,
            ],
        ];

        $response = $this->request($credentials)
            ->post(self::BASE_URL.'/api/merchant/invoice/create', $payload);

        if (! $response->successful() || ! is_string($response->json('invoiceId')) || ! is_string($response->json('pageUrl'))) {
            throw new PaymentGatewayException('Monopay card verification creation failed.');
        }

        return PaymentCheckout::redirect((string) $response->json('pageUrl'), [
            'request' => $payload,
            'response' => $response->json(),
        ]);
    }

    /**
     * @return array{request: array<string, mixed>, response: array<string, mixed>}
     */
    public function charge(
        AccountSubscriptionPayment $payment,
        AccountSubscriptionPaymentMethod $paymentMethod,
        IntegrationSetting $setting,
        string $redirectUrl,
        bool $ownerInitiated = false,
    ): array {
        if (! $paymentMethod->isActive()) {
            throw new PaymentGatewayException('A verified payment method is required.');
        }

        $payload = [
            'cardToken' => $paymentMethod->provider_card_token,
            'amount' => $payment->amount_cents,
            'ccy' => PaymentAmounts::iso4217NumericCode($payment->currency),
            'redirectUrl' => $redirectUrl,
            'webHookUrl' => route('api.v1.saas.payments.callbacks', IntegrationProvider::Monopay->value),
            'initiationKind' => $ownerInitiated ? 'client' : 'merchant',
            'merchantPaymInfo' => $this->merchantPaymentInfo($payment),
            'paymentType' => 'debit',
        ];

        $response = $this->request($setting->readableCredentials())
            ->post(self::BASE_URL.'/api/merchant/wallet/payment', $payload);

        $responsePayload = $response->json();

        if (! $response->successful() || ! is_array($responsePayload) || ! is_string($responsePayload['invoiceId'] ?? null)) {
            throw new PaymentGatewayException('Monopay token payment failed to start.');
        }

        return [
            'request' => $payload,
            'response' => $responsePayload,
        ];
    }

    public function revokeCard(AccountSubscriptionPaymentMethod $paymentMethod, IntegrationSetting $setting): void
    {
        if (! filled($paymentMethod->provider_card_token)) {
            return;
        }

        $response = $this->request($setting->readableCredentials())
            ->withQueryParameters([
                'cardToken' => $paymentMethod->provider_card_token,
            ])
            ->delete(self::BASE_URL.'/api/merchant/wallet/card');

        if (! $response->successful() && ! $response->notFound()) {
            throw new PaymentGatewayException('Monopay token revocation failed.');
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function request(array $credentials): PendingRequest
    {
        return Http::withHeaders(['X-Token' => (string) ($credentials['api_token'] ?? '')])
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->connectTimeout(3)
            ->retry([100, 300]);
    }

    /**
     * @return array<string, mixed>
     */
    private function merchantPaymentInfo(AccountSubscriptionPayment $payment): array
    {
        $name = $payment->plan_name_snapshot ?: $payment->plan?->name ?: 'Ladna SaaS';

        return [
            'reference' => $payment->order_id,
            'destination' => $name,
            'comment' => $name,
            'basketOrder' => [[
                'name' => $name,
                'qty' => 1,
                'sum' => $payment->amount_cents,
                'total' => $payment->amount_cents,
                'unit' => 'pcs',
                'code' => $payment->order_id,
            ]],
        ];
    }
}

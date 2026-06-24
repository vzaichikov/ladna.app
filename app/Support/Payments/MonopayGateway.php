<?php

namespace App\Support\Payments;

use App\Enums\IntegrationProvider;
use App\Models\CustomerPurchase;
use App\Models\IntegrationSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class MonopayGateway implements PaymentGateway
{
    private const BASE_URL = 'https://api.monobank.ua';

    public function provider(): IntegrationProvider
    {
        return IntegrationProvider::Monopay;
    }

    public function start(CustomerPurchase $purchase, IntegrationSetting $setting): PaymentCheckout
    {
        $credentials = $setting->readableCredentials();
        $payload = [
            'amount' => $purchase->amount_cents,
            'ccy' => PaymentAmounts::iso4217NumericCode($purchase->currency),
            'merchantPaymInfo' => [
                'reference' => $purchase->order_id,
                'destination' => $purchase->plan_name,
                'comment' => $purchase->plan_name,
                'customerEmails' => array_values(array_filter([$purchase->customer->email])),
                'basketOrder' => [[
                    'name' => $purchase->plan_name,
                    'qty' => 1,
                    'sum' => $purchase->amount_cents,
                    'total' => $purchase->amount_cents,
                    'unit' => 'pcs',
                    'code' => $purchase->order_id,
                ]],
            ],
            'redirectUrl' => route('customer.purchases.return', [$purchase->account->slug, $purchase]),
            'webHookUrl' => route('api.v1.payments.callbacks', $this->provider()->value),
            'validity' => (int) ($credentials['invoice_validity_seconds'] ?? 3600),
            'paymentType' => (string) ($credentials['payment_type'] ?? 'debit'),
        ];

        if (filled($credentials['qr_id'] ?? null)) {
            $payload['qrId'] = (string) $credentials['qr_id'];
        }

        if (filled($credentials['submerchant_code'] ?? null)) {
            $payload['code'] = (string) $credentials['submerchant_code'];
        }

        $response = Http::withHeaders(['X-Token' => (string) $credentials['api_token']])
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->connectTimeout(3)
            ->retry([100, 300])
            ->post(self::BASE_URL.'/api/merchant/invoice/create', $payload);

        if (! $response->successful() || ! is_string($response->json('pageUrl'))) {
            throw new PaymentGatewayException('Monopay invoice creation failed.');
        }

        return PaymentCheckout::redirect((string) $response->json('pageUrl'), [
            'request' => $payload,
            'response' => $response->json(),
        ]);
    }

    public function orderIdFromCallback(Request $request): ?string
    {
        $reference = $request->json('reference') ?? $request->input('reference');

        return is_string($reference) ? $reference : null;
    }

    public function handleCallback(Request $request, IntegrationSetting $setting): PaymentCallbackResult
    {
        $credentials = $setting->readableCredentials();
        $payload = $request->json()->all();
        $signature = (string) $request->header('X-Sign', '');
        $publicKey = $this->publicKey($credentials);

        if ($signature === '' || ! $this->verifySignature($request->getContent(), $signature, $publicKey)) {
            throw new InvalidPaymentCallbackException('Invalid Monopay callback signature.');
        }

        $status = (string) ($payload['status'] ?? '');

        return new PaymentCallbackResult(
            orderId: (string) ($payload['reference'] ?? ''),
            status: $this->callbackStatus($status),
            gatewayStatus: $status,
            amountCents: isset($payload['finalAmount']) ? (int) $payload['finalAmount'] : (isset($payload['amount']) ? (int) $payload['amount'] : null),
            currency: isset($payload['ccy']) ? PaymentAmounts::currencyFromIso4217($payload['ccy']) : null,
            gatewayInvoiceId: is_string($payload['invoiceId'] ?? null) ? $payload['invoiceId'] : null,
            failureReason: isset($payload['failureReason']) || isset($payload['errCode'])
                ? (string) ($payload['failureReason'] ?? $payload['errCode'])
                : null,
            paidAt: isset($payload['modifiedDate']) && is_string($payload['modifiedDate'])
                ? Carbon::parse($payload['modifiedDate'])
                : null,
            payload: $payload,
        );
    }

    public function callbackResponse(CustomerPurchase $purchase, IntegrationSetting $setting): Response
    {
        return response('OK');
    }

    public function verifySignature(string $body, string $signature, string $publicKeyBase64): bool
    {
        $decodedSignature = base64_decode($signature, true);
        $decodedPublicKey = base64_decode($publicKeyBase64, true);

        if (! is_string($decodedSignature) || ! is_string($decodedPublicKey)) {
            return false;
        }

        $publicKey = openssl_get_publickey($decodedPublicKey);

        if ($publicKey === false) {
            return false;
        }

        return openssl_verify($body, $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function publicKey(array $credentials): string
    {
        if (filled($credentials['webhook_public_key'] ?? null)) {
            return (string) $credentials['webhook_public_key'];
        }

        $response = Http::withHeaders(['X-Token' => (string) $credentials['api_token']])
            ->acceptJson()
            ->timeout(5)
            ->connectTimeout(3)
            ->retry([100, 300])
            ->get(self::BASE_URL.'/api/merchant/pubkey');

        $key = $response->json('key') ?? $response->json('publicKey');

        if (! $response->successful() || ! is_string($key) || $key === '') {
            throw new InvalidPaymentCallbackException('Monopay public key is unavailable.');
        }

        return $key;
    }

    private function callbackStatus(string $status): PaymentCallbackStatus
    {
        return match ($status) {
            'success' => PaymentCallbackStatus::Paid,
            'failure' => PaymentCallbackStatus::Failed,
            'expired' => PaymentCallbackStatus::Expired,
            'reversed', 'cancelled' => PaymentCallbackStatus::Cancelled,
            default => PaymentCallbackStatus::Pending,
        };
    }
}

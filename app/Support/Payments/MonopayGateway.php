<?php

namespace App\Support\Payments;

use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Uri;
use League\Uri\Contracts\UriException;
use Symfony\Component\HttpFoundation\Response;

class MonopayGateway implements PaymentGateway
{
    private const BASE_URL = 'https://api.monobank.ua';

    private const CHECKOUT_HOST = 'pay.monobank.ua';

    public function provider(): IntegrationProvider
    {
        return IntegrationProvider::Monopay;
    }

    public function start(PaymentCheckoutRequest $checkout, IntegrationSetting $setting): PaymentCheckout
    {
        $credentials = $setting->readableCredentials();
        $payload = [
            'amount' => $checkout->amountCents,
            'ccy' => PaymentAmounts::iso4217NumericCode($checkout->currency),
            'merchantPaymInfo' => [
                'reference' => $checkout->reference,
                'destination' => $checkout->description,
                'comment' => $checkout->description,
                'customerEmails' => array_values(array_filter([$checkout->buyerEmail])),
                'basketOrder' => [[
                    'name' => $checkout->description,
                    'qty' => 1,
                    'sum' => $checkout->amountCents,
                    'total' => $checkout->amountCents,
                    'unit' => 'pcs',
                    'code' => $checkout->reference,
                ]],
            ],
            'redirectUrl' => $checkout->returnUrl,
            'webHookUrl' => $checkout->callbackUrl,
            'validity' => (int) max(60, now()->diffInSeconds($checkout->expiresAt, false)),
            'paymentType' => 'debit',
        ];

        if (filled($credentials['qr_id'] ?? null)) {
            $payload['qrId'] = (string) $credentials['qr_id'];
        }

        if ($checkout->preferIframe) {
            $payload['displayType'] = 'iframe';
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

        $pageUrl = (string) $response->json('pageUrl');
        $gatewayPayload = [
            'request' => $payload,
            'response' => $response->json(),
        ];

        if ($checkout->preferIframe) {
            if (! self::trustedIframeOrigin($pageUrl)) {
                throw new PaymentGatewayException('Monopay iframe checkout URL is invalid.');
            }

            return PaymentCheckout::iframe($pageUrl, $gatewayPayload);
        }

        return PaymentCheckout::redirect($pageUrl, $gatewayPayload);
    }

    public static function trustedIframeOrigin(string $pageUrl): ?string
    {
        try {
            $uri = Uri::of($pageUrl);
        } catch (UriException) {
            return null;
        }

        if ($uri->scheme() !== 'https' || $uri->host() !== self::CHECKOUT_HOST || $uri->port() !== null) {
            return null;
        }

        return 'https://'.self::CHECKOUT_HOST;
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

    public function callbackResponse(string $reference, IntegrationSetting $setting): Response
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

<?php

namespace App\Support\SaasBilling;

use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Models\AccountSubscriptionPayment;
use App\Models\IntegrationSetting;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\Payments\PaymentAmounts;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use App\Support\Payments\PaymentCheckout;
use App\Support\Payments\PaymentGatewayException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class MonopaySaasBilling
{
    private const BASE_URL = 'https://api.monobank.ua';

    public function platformSetting(): ?IntegrationSetting
    {
        return IntegrationSetting::platform()
            ->where('provider', IntegrationProvider::Monopay->value)
            ->where('category', IntegrationCategory::Payment->value)
            ->where('is_enabled', true)
            ->first();
    }

    public function startOneTimePayment(AccountSubscriptionPayment $payment, IntegrationSetting $setting, string $redirectUrl): PaymentCheckout
    {
        $credentials = $setting->readableCredentials();
        $payload = [
            'amount' => $payment->amount_cents,
            'ccy' => PaymentAmounts::iso4217NumericCode($payment->currency),
            'merchantPaymInfo' => $this->merchantPaymentInfo($payment),
            'redirectUrl' => $redirectUrl,
            'webHookUrl' => route('api.v1.saas.payments.callbacks', IntegrationProvider::Monopay->value),
            'validity' => (int) ($credentials['invoice_validity_seconds'] ?? 3600),
            'paymentType' => 'debit',
        ];

        if (filled($credentials['qr_id'] ?? null)) {
            $payload['qrId'] = (string) $credentials['qr_id'];
        }

        if ($payment->payment_type === AccountSubscriptionPaymentType::DemoInitial) {
            $payload['displayType'] = 'iframe';
        }

        $response = $this->request($credentials)
            ->post(self::BASE_URL.'/api/merchant/invoice/create', $payload);

        if (! $response->successful() || ! is_string($response->json('pageUrl'))) {
            throw new PaymentGatewayException('Monopay SaaS invoice creation failed.');
        }

        return PaymentCheckout::redirect((string) $response->json('pageUrl'), [
            'request' => $payload,
            'response' => $response->json(),
        ]);
    }

    public function startRecurringPayment(AccountSubscriptionPayment $payment, IntegrationSetting $setting, string $redirectUrl): PaymentCheckout
    {
        $credentials = $setting->readableCredentials();
        $payload = [
            'amount' => $payment->amount_cents,
            'ccy' => PaymentAmounts::iso4217NumericCode($payment->currency),
            'redirectUrl' => $redirectUrl,
            'webHookUrls' => [
                'payment' => route('api.v1.saas.payments.callbacks', IntegrationProvider::Monopay->value),
            ],
            'interval' => $this->interval($payment),
            'validity' => (int) ($credentials['invoice_validity_seconds'] ?? 3600),
        ];

        $response = $this->request($credentials)
            ->post(self::BASE_URL.'/api/merchant/subscription/create', $payload);

        if (! $response->successful() || ! is_string($response->json('pageUrl'))) {
            throw new PaymentGatewayException('Monopay SaaS subscription creation failed.');
        }

        return PaymentCheckout::redirect((string) $response->json('pageUrl'), [
            'request' => $payload,
            'response' => $response->json(),
        ]);
    }

    public function orderIdFromCallback(Request $request): ?string
    {
        foreach (['reference', 'subscriptionId', 'invoiceId', 'paymentId'] as $key) {
            $reference = $request->json($key) ?? $request->input($key);

            if (is_string($reference) && $reference !== '') {
                return $reference;
            }
        }

        return null;
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

        $orderId = null;

        foreach (['reference', 'subscriptionId', 'invoiceId', 'paymentId'] as $key) {
            if (is_string($payload[$key] ?? null) && $payload[$key] !== '') {
                $orderId = $payload[$key];
                break;
            }
        }

        return new PaymentCallbackResult(
            orderId: (string) $orderId,
            status: $this->callbackStatus($status),
            gatewayStatus: $status,
            amountCents: isset($payload['finalAmount']) ? (int) $payload['finalAmount'] : (isset($payload['amount']) ? (int) $payload['amount'] : null),
            currency: isset($payload['ccy']) ? PaymentAmounts::currencyFromIso4217($payload['ccy']) : null,
            gatewayInvoiceId: is_string($payload['invoiceId'] ?? null) ? $payload['invoiceId'] : null,
            gatewayPaymentId: is_string($payload['paymentId'] ?? null) ? $payload['paymentId'] : null,
            failureReason: isset($payload['failureReason']) || isset($payload['errCode'])
                ? (string) ($payload['failureReason'] ?? $payload['errCode'])
                : null,
            paidAt: isset($payload['modifiedDate']) && is_string($payload['modifiedDate'])
                ? Carbon::parse($payload['modifiedDate'])
                : null,
            payload: $payload,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function subscriptionStatus(string $subscriptionId, IntegrationSetting $setting): ?array
    {
        $response = $this->request($setting->readableCredentials())
            ->get(self::BASE_URL.'/api/merchant/subscription/status', [
                'subscriptionId' => $subscriptionId,
            ]);

        if ($response->notFound()) {
            return null;
        }

        $response->throw();

        $payload = $response->json();

        return is_array($payload) ? $payload : null;
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
        return [
            'reference' => $payment->order_id,
            'destination' => $payment->plan?->name ?? 'Ladna SaaS',
            'comment' => $payment->plan?->name ?? 'Ladna SaaS',
            'basketOrder' => [[
                'name' => $payment->plan?->name ?? 'Ladna SaaS',
                'qty' => 1,
                'sum' => $payment->amount_cents,
                'total' => $payment->amount_cents,
                'unit' => 'pcs',
                'code' => $payment->order_id,
            ]],
        ];
    }

    private function interval(AccountSubscriptionPayment $payment): string
    {
        return match ($payment->plan?->billing_interval) {
            'yearly' => '1y',
            default => '1m',
        };
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function publicKey(array $credentials): string
    {
        $response = $this->request($credentials)
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

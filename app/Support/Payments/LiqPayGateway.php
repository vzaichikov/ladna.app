<?php

namespace App\Support\Payments;

use App\Enums\IntegrationProvider;
use App\Models\CustomerPurchase;
use App\Models\IntegrationSetting;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LiqPayGateway implements PaymentGateway
{
    private const CHECKOUT_URL = 'https://www.liqpay.ua/api/3/checkout';

    public function provider(): IntegrationProvider
    {
        return IntegrationProvider::Liqpay;
    }

    public function start(CustomerPurchase $purchase, IntegrationSetting $setting): PaymentCheckout
    {
        $credentials = $setting->readableCredentials();
        $data = [
            'public_key' => (string) $credentials['public_key'],
            'version' => (int) ($credentials['api_version'] ?? 7),
            'action' => 'pay',
            'amount' => PaymentAmounts::centsToDecimalString($purchase->amount_cents),
            'currency' => $purchase->currency,
            'description' => $purchase->plan_name,
            'order_id' => $purchase->order_id,
            'result_url' => route('customer.purchases.return', [$purchase->account->slug, $purchase]),
            'server_url' => route('api.v1.payments.callbacks', $this->provider()->value),
            'customer' => (string) $purchase->customer_id,
            'language' => app()->getLocale() === 'en' ? 'en' : 'uk',
        ];

        $encodedData = base64_encode((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $signature = $this->signature($encodedData, (string) $credentials['private_key']);

        return PaymentCheckout::form(self::CHECKOUT_URL, [
            'data' => $encodedData,
            'signature' => $signature,
        ], [
            'checkout_data' => $data,
        ]);
    }

    public function orderIdFromCallback(Request $request): ?string
    {
        $payload = $this->decodeData((string) $request->input('data', ''));

        return is_string($payload['order_id'] ?? null) ? $payload['order_id'] : null;
    }

    public function handleCallback(Request $request, IntegrationSetting $setting): PaymentCallbackResult
    {
        $credentials = $setting->readableCredentials();
        $data = (string) $request->input('data', '');
        $signature = (string) $request->input('signature', '');
        $expected = $this->signature($data, (string) $credentials['private_key']);

        if ($data === '' || $signature === '' || ! hash_equals($expected, $signature)) {
            throw new InvalidPaymentCallbackException('Invalid LiqPay callback signature.');
        }

        $payload = $this->decodeData($data);
        $status = (string) ($payload['status'] ?? '');

        return new PaymentCallbackResult(
            orderId: (string) ($payload['order_id'] ?? ''),
            status: $this->callbackStatus($status),
            gatewayStatus: $status,
            amountCents: PaymentAmounts::decimalToCents($payload['amount'] ?? null),
            currency: is_string($payload['currency'] ?? null) ? $payload['currency'] : null,
            gatewayInvoiceId: is_string($payload['liqpay_order_id'] ?? null) ? $payload['liqpay_order_id'] : null,
            gatewayPaymentId: isset($payload['payment_id']) ? (string) $payload['payment_id'] : null,
            failureReason: isset($payload['err_description']) || isset($payload['err_code'])
                ? (string) ($payload['err_description'] ?? $payload['err_code'])
                : null,
            payload: $payload,
        );
    }

    public function callbackResponse(CustomerPurchase $purchase, IntegrationSetting $setting): Response
    {
        return response('OK');
    }

    public function signature(string $data, string $privateKey): string
    {
        return base64_encode(sha1($privateKey.$data.$privateKey, true));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeData(string $data): array
    {
        $decoded = base64_decode($data, true);

        if (! is_string($decoded)) {
            return [];
        }

        $payload = json_decode($decoded, true);

        return is_array($payload) ? $payload : [];
    }

    private function callbackStatus(string $status): PaymentCallbackStatus
    {
        return match ($status) {
            'success' => PaymentCallbackStatus::Paid,
            'failure', 'error' => PaymentCallbackStatus::Failed,
            'reversed', 'unsubscribed' => PaymentCallbackStatus::Cancelled,
            default => PaymentCallbackStatus::Pending,
        };
    }
}

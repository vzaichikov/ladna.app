<?php

namespace App\Support\Payments;

use App\Enums\IntegrationProvider;
use App\Models\CustomerPurchase;
use App\Models\IntegrationSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class WayForPayGateway implements PaymentGateway
{
    private const CHECKOUT_URL = 'https://secure.wayforpay.com/pay';

    public function provider(): IntegrationProvider
    {
        return IntegrationProvider::Wayforpay;
    }

    public function start(CustomerPurchase $purchase, IntegrationSetting $setting): PaymentCheckout
    {
        $credentials = $setting->readableCredentials();
        $amount = PaymentAmounts::centsToDecimalString($purchase->amount_cents);
        $orderDate = now()->timestamp;
        $productName = [$purchase->plan_name];
        $productCount = [1];
        $productPrice = [$amount];
        $merchantAccount = (string) $credentials['merchant_account'];
        $merchantDomainName = (string) $credentials['merchant_domain_name'];

        $fields = [
            'merchantAccount' => $merchantAccount,
            'merchantAuthType' => (string) ($credentials['merchant_auth_type'] ?? 'SimpleSignature'),
            'merchantDomainName' => $merchantDomainName,
            'orderReference' => $purchase->order_id,
            'orderDate' => $orderDate,
            'amount' => $amount,
            'currency' => $purchase->currency,
            'productName[]' => $productName,
            'productPrice[]' => $productPrice,
            'productCount[]' => $productCount,
            'clientEmail' => $purchase->customer->email,
            'clientPhone' => $purchase->customer->phone,
            'serviceUrl' => route('api.v1.payments.callbacks', $this->provider()->value),
            'returnUrl' => route('customer.purchases.return', [$purchase->account->slug, $purchase]),
            'language' => app()->getLocale() === 'en' ? 'EN' : 'UA',
        ];
        $fields['merchantSignature'] = $this->checkoutSignature(
            $merchantAccount,
            $merchantDomainName,
            $purchase->order_id,
            $orderDate,
            $amount,
            $purchase->currency,
            $productName,
            $productCount,
            $productPrice,
            (string) $credentials['merchant_secret_key'],
        );

        return PaymentCheckout::form(self::CHECKOUT_URL, $fields, [
            'checkout_fields' => $fields,
        ]);
    }

    public function orderIdFromCallback(Request $request): ?string
    {
        $payload = $this->payload($request);

        return is_string($payload['orderReference'] ?? null) ? $payload['orderReference'] : null;
    }

    public function handleCallback(Request $request, IntegrationSetting $setting): PaymentCallbackResult
    {
        $credentials = $setting->readableCredentials();
        $payload = $this->payload($request);
        $signature = (string) ($payload['merchantSignature'] ?? '');
        $expected = $this->callbackSignature($payload, (string) $credentials['merchant_secret_key']);

        if ($signature === '' || ! hash_equals($expected, $signature)) {
            throw new InvalidPaymentCallbackException('Invalid WayForPay callback signature.');
        }

        $status = (string) ($payload['transactionStatus'] ?? '');

        return new PaymentCallbackResult(
            orderId: (string) ($payload['orderReference'] ?? ''),
            status: $this->callbackStatus($status),
            gatewayStatus: $status,
            amountCents: PaymentAmounts::decimalToCents($payload['amount'] ?? null),
            currency: is_string($payload['currency'] ?? null) ? $payload['currency'] : null,
            gatewayPaymentId: is_string($payload['authCode'] ?? null) ? $payload['authCode'] : null,
            failureReason: isset($payload['reason']) || isset($payload['reasonCode'])
                ? (string) ($payload['reason'] ?? $payload['reasonCode'])
                : null,
            paidAt: isset($payload['processingDate']) && is_numeric($payload['processingDate'])
                ? Carbon::createFromTimestamp((int) $payload['processingDate'])
                : null,
            payload: $payload,
        );
    }

    public function callbackResponse(CustomerPurchase $purchase, IntegrationSetting $setting): Response
    {
        $time = now()->timestamp;
        $credentials = $setting->readableCredentials();

        return response()->json($this->acceptResponsePayload(
            $purchase->order_id,
            $time,
            (string) $credentials['merchant_secret_key'],
        ));
    }

    /**
     * @param  array<int, string>  $productName
     * @param  array<int, int>  $productCount
     * @param  array<int, string>  $productPrice
     */
    public function checkoutSignature(
        string $merchantAccount,
        string $merchantDomainName,
        string $orderReference,
        int $orderDate,
        string $amount,
        string $currency,
        array $productName,
        array $productCount,
        array $productPrice,
        string $secretKey,
    ): string {
        return hash_hmac('md5', implode(';', [
            $merchantAccount,
            $merchantDomainName,
            $orderReference,
            $orderDate,
            $amount,
            $currency,
            ...$productName,
            ...array_map('strval', $productCount),
            ...$productPrice,
        ]), $secretKey);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function callbackSignature(array $payload, string $secretKey): string
    {
        return hash_hmac('md5', implode(';', [
            (string) ($payload['merchantAccount'] ?? ''),
            (string) ($payload['orderReference'] ?? ''),
            (string) ($payload['amount'] ?? ''),
            (string) ($payload['currency'] ?? ''),
            (string) ($payload['authCode'] ?? ''),
            (string) ($payload['cardPan'] ?? ''),
            (string) ($payload['transactionStatus'] ?? ''),
            (string) ($payload['reasonCode'] ?? ''),
        ]), $secretKey);
    }

    /**
     * @return array{orderReference: string, status: string, time: int, signature: string}
     */
    public function acceptResponsePayload(string $orderReference, int $time, ?string $secretKey = null): array
    {
        $status = 'accept';
        $signature = $secretKey
            ? hash_hmac('md5', implode(';', [$orderReference, $status, $time]), $secretKey)
            : '';

        return [
            'orderReference' => $orderReference,
            'status' => $status,
            'time' => $time,
            'signature' => $signature,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);

        return is_array($payload) ? $payload : $request->all();
    }

    private function callbackStatus(string $status): PaymentCallbackStatus
    {
        return match ($status) {
            'Approved' => PaymentCallbackStatus::Paid,
            'Expired' => PaymentCallbackStatus::Expired,
            'Refunded', 'Voided' => PaymentCallbackStatus::Cancelled,
            'Declined' => PaymentCallbackStatus::Failed,
            default => PaymentCallbackStatus::Pending,
        };
    }
}

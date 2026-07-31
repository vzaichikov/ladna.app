<?php

namespace App\Support\CustomerAuth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class SmsClubGateway implements SmsGateway, SmsGatewayBalanceProvider, SmsGatewayStatusProvider
{
    private const int MaxStatusBatchSize = 100;

    private const int MaxStatusRequestsPerSecond = 9;

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function __construct(private array $credentials) {}

    public function sendOtp(string $phone, string $message): SmsGatewayResult
    {
        return $this->sendSms($phone, $message);
    }

    public function sendSms(string $phone, string $message): SmsGatewayResult
    {
        $payload = [
            'phone' => [$phone],
            'message' => $message,
            'src_addr' => (string) ($this->credentials['src_addr'] ?? ''),
        ];

        if (filled($this->credentials['integration_id'] ?? null)) {
            $payload['integration_id'] = (string) $this->credentials['integration_id'];
        }

        try {
            $response = $this->request()->post('https://im.smsclub.mobi/sms/send', $payload);
        } catch (ConnectionException) {
            return SmsGatewayResult::unknown('Smsclub request outcome is unknown.');
        }

        if ($response->successful()) {
            $providerMessageId = $this->providerMessageId($response);

            if ($providerMessageId === null) {
                return SmsGatewayResult::unknown('Smsclub accepted the request without a message identifier.');
            }

            return SmsGatewayResult::sent($providerMessageId);
        }

        if ($response->serverError()) {
            return SmsGatewayResult::unknown(sprintf(
                'Smsclub request outcome is unknown after HTTP %d.',
                $response->status(),
            ));
        }

        return SmsGatewayResult::failed(sprintf(
            'Smsclub rejected the request with HTTP %d.',
            $response->status(),
        ));
    }

    public function maxStatusBatchSize(): int
    {
        return self::MaxStatusBatchSize;
    }

    public function maxStatusRequestsPerSecond(): int
    {
        return self::MaxStatusRequestsPerSecond;
    }

    public function fetchDeliveryStatuses(array $providerMessageIds): SmsGatewayStatusBatchResult
    {
        $providerMessageIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): string => trim((string) $id), $providerMessageIds),
        )));

        if (count($providerMessageIds) > self::MaxStatusBatchSize) {
            throw new InvalidArgumentException('Smsclub accepts at most 100 message identifiers per status request.');
        }

        if ($providerMessageIds === []) {
            return SmsGatewayStatusBatchResult::success([], []);
        }

        try {
            $response = $this->request()->post('https://im.smsclub.mobi/sms/status', [
                'id_sms' => $providerMessageIds,
            ]);
        } catch (ConnectionException) {
            return SmsGatewayStatusBatchResult::failed('Smsclub status request failed to connect.');
        }

        if (! $response->successful()) {
            return SmsGatewayStatusBatchResult::failed(sprintf(
                'Smsclub status request failed with HTTP %d.',
                $response->status(),
            ));
        }

        $info = $response->json('success_request.info');

        if (! is_array($info)) {
            return SmsGatewayStatusBatchResult::failed('Smsclub status response is malformed.');
        }

        $statuses = [];
        $providerStatuses = [];

        foreach ($info as $providerMessageId => $providerStatus) {
            if (! is_scalar($providerStatus)) {
                continue;
            }

            $normalizedProviderMessageId = (string) $providerMessageId;
            $normalizedProviderStatus = strtoupper(trim((string) $providerStatus));

            $providerStatuses[$normalizedProviderMessageId] = $normalizedProviderStatus;
            $statuses[$normalizedProviderMessageId] = $this->deliveryStatus($normalizedProviderStatus);
        }

        return SmsGatewayStatusBatchResult::success($statuses, $providerStatuses);
    }

    public function fetchBalance(): SmsGatewayBalanceResult
    {
        try {
            $response = $this->request()->post('https://im.smsclub.mobi/sms/balance');
        } catch (ConnectionException) {
            return SmsGatewayBalanceResult::failed('Smsclub balance request failed to connect.');
        }

        if (! $response->successful()) {
            return SmsGatewayBalanceResult::failed(sprintf(
                'Smsclub balance request failed with HTTP %d.',
                $response->status(),
            ));
        }

        $info = $response->json('success_request.info');

        if (is_array($info) && isset($info[0]) && is_array($info[0])) {
            $info = $info[0];
        }

        $amount = is_array($info) ? $info['money'] ?? null : null;
        $currency = is_array($info) ? $info['currency'] ?? null : null;

        if (
            ! is_scalar($amount)
            || ! is_string($currency)
            || preg_match('/^-?\d+(?:\.\d+)?$/', trim((string) $amount)) !== 1
            || preg_match('/^[A-Za-z]{3}$/', trim($currency)) !== 1
        ) {
            return SmsGatewayBalanceResult::failed('Smsclub balance response is malformed.');
        }

        return SmsGatewayBalanceResult::success(
            trim((string) $amount),
            strtoupper(trim($currency)),
        );
    }

    private function request(): PendingRequest
    {
        return Http::withToken((string) ($this->credentials['bearer_token'] ?? ''))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(3)
            ->timeout(10);
    }

    private function providerMessageId(Response $response): ?string
    {
        $info = $response->json('success_request.info');

        if (is_array($info) && $info !== [] && ! array_is_list($info)) {
            $providerMessageId = trim((string) array_key_first($info));

            if ($providerMessageId !== '') {
                return $providerMessageId;
            }
        }

        foreach (['success_request.info.0.id', 'info.0.id'] as $path) {
            $providerMessageId = $response->json($path);

            if (is_scalar($providerMessageId) && trim((string) $providerMessageId) !== '') {
                return trim((string) $providerMessageId);
            }
        }

        return null;
    }

    private function deliveryStatus(string $providerStatus): SmsGatewayDeliveryStatus
    {
        return match ($providerStatus) {
            'ENROUTE' => SmsGatewayDeliveryStatus::Accepted,
            'DELIVRD' => SmsGatewayDeliveryStatus::Delivered,
            'EXPIRED', 'UNDELIV', 'REJECTD' => SmsGatewayDeliveryStatus::Undelivered,
            default => SmsGatewayDeliveryStatus::Unknown,
        };
    }
}

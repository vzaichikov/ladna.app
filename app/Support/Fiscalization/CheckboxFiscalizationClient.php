<?php

namespace App\Support\Fiscalization;

use App\Enums\FiscalReceiptStatus;
use App\Models\IntegrationSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CheckboxFiscalizationClient
{
    private const BaseUrl = 'https://api.checkbox.ua';

    private const OpenShiftStatus = 'OPENED';

    /** @var array<int, string> */
    private const OpeningShiftStatuses = ['CREATED', 'OPENING'];

    /** @var array<int, string> */
    private const ProcessingReceiptStatuses = ['CREATED', 'PROCESSING', 'PENDING'];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sell(IntegrationSetting $setting, array $payload): FiscalizationResult
    {
        $token = $this->token($setting);

        if ($token === null) {
            return FiscalizationResult::failed(null, null, 'Checkbox sign in failed.');
        }

        $shiftError = $this->ensureShiftIsOpen($setting, $token);

        if ($shiftError !== null) {
            return FiscalizationResult::failed(null, null, $shiftError);
        }

        $response = $this->authorizedRequest($setting, $token)
            ->post(self::BaseUrl.'/api/v1/receipts/sell', $payload);

        $result = $this->resultFromResponse($response);

        if ($result->status !== FiscalReceiptStatus::Processing || ! $result->providerReceiptId) {
            return $result;
        }

        return $this->pollReceipt($setting, $token, $result->providerReceiptId, $result);
    }

    public function status(IntegrationSetting $setting, string $providerReceiptId): FiscalizationResult
    {
        $token = $this->token($setting);

        if ($token === null) {
            return FiscalizationResult::failed($providerReceiptId, null, 'Checkbox sign in failed.');
        }

        $response = $this->authorizedRequest($setting, $token)
            ->get(self::BaseUrl.'/api/v1/receipts/'.$providerReceiptId);

        return $this->resultFromResponse($response);
    }

    private function token(IntegrationSetting $setting): ?string
    {
        $credentials = $setting->readableCredentials();
        $response = filled($credentials['cashier_login'] ?? null) && filled($credentials['cashier_password'] ?? null)
            ? $this->baseRequest($setting)->post(self::BaseUrl.'/api/v1/cashier/signin', [
                'login' => (string) $credentials['cashier_login'],
                'password' => (string) $credentials['cashier_password'],
            ])
            : $this->licensedRequest($setting)->post(self::BaseUrl.'/api/v1/cashier/signinPinCode', [
                'pin_code' => (string) ($credentials['cashier_pin_code'] ?? ''),
            ]);

        if (! $response->successful()) {
            return null;
        }

        $token = $response->json('access_token') ?? $response->json('token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function baseRequest(IntegrationSetting $setting): PendingRequest
    {
        $credentials = $setting->readableCredentials();

        $headers = [
            'X-Client-Name' => (string) ($credentials['client_name'] ?? 'Ladna'),
            'X-Client-Version' => (string) ($credentials['client_version'] ?? config('app.version', '1.0')),
        ];

        if (filled($credentials['device_id'] ?? null)) {
            $headers['X-Device-ID'] = (string) $credentials['device_id'];
        }

        return Http::withHeaders($headers)
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->connectTimeout(3)
            ->retry([100, 300], throw: false);
    }

    private function authorizedRequest(IntegrationSetting $setting, string $token): PendingRequest
    {
        return $this->baseRequest($setting)->withToken($token);
    }

    private function licensedRequest(IntegrationSetting $setting, ?string $token = null): PendingRequest
    {
        $request = $this->baseRequest($setting)->withHeaders([
            'X-License-Key' => (string) ($setting->readableCredentials()['license_key'] ?? ''),
        ]);

        return $token ? $request->withToken($token) : $request;
    }

    private function ensureShiftIsOpen(IntegrationSetting $setting, string $token): ?string
    {
        $currentShiftResponse = $this->authorizedRequest($setting, $token)
            ->get(self::BaseUrl.'/api/v1/cashier/shift');

        if ($currentShiftResponse->successful()) {
            $currentShift = $this->jsonPayload($currentShiftResponse);
            $currentStatus = $this->normalizedStatus($currentShift);

            if ($currentStatus === self::OpenShiftStatus) {
                return null;
            }

            if (in_array($currentStatus, self::OpeningShiftStatuses, true)) {
                $shiftId = $this->firstString($currentShift, ['id', 'shift_id']);

                return $shiftId
                    ? $this->waitForOpenedShift($setting, $token, $shiftId)
                    : 'Checkbox shift is opening, but response did not include a shift ID.';
            }

            if ($currentStatus === 'CLOSING') {
                return 'Checkbox shift is closing. Try fiscalization again after the shift is closed.';
            }
        } elseif (! in_array($currentShiftResponse->status(), [404, 422], true)) {
            return $this->errorFromPayload($this->jsonPayload($currentShiftResponse)) ?? 'Checkbox current shift check failed.';
        }

        $shiftId = (string) Str::uuid();
        $openShiftResponse = $this->licensedRequest($setting, $token)
            ->post(self::BaseUrl.'/api/v1/shifts', [
                'id' => $shiftId,
            ]);

        if (! $openShiftResponse->successful()) {
            return $this->errorFromPayload($this->jsonPayload($openShiftResponse)) ?? 'Checkbox shift opening failed.';
        }

        $openedShift = $this->jsonPayload($openShiftResponse);
        $returnedShiftId = $this->firstString($openedShift, ['id', 'shift_id']) ?? $shiftId;

        if ($this->normalizedStatus($openedShift) === self::OpenShiftStatus) {
            return null;
        }

        return $this->waitForOpenedShift($setting, $token, $returnedShiftId);
    }

    private function waitForOpenedShift(IntegrationSetting $setting, string $token, string $shiftId): ?string
    {
        $lastStatus = null;

        foreach ([0, 300_000, 700_000, 1_000_000, 1_000_000] as $delayMicroseconds) {
            if ($delayMicroseconds > 0) {
                usleep($delayMicroseconds);
            }

            $response = $this->authorizedRequest($setting, $token)
                ->get(self::BaseUrl.'/api/v1/shifts/'.$shiftId);
            $payload = $this->jsonPayload($response);

            if (! $response->successful()) {
                return $this->errorFromPayload($payload) ?? 'Checkbox shift status check failed.';
            }

            $lastStatus = $this->normalizedStatus($payload);

            if ($lastStatus === self::OpenShiftStatus) {
                return null;
            }

            if (! in_array($lastStatus, self::OpeningShiftStatuses, true)) {
                return "Checkbox shift is not open. Current status: {$lastStatus}.";
            }
        }

        return "Checkbox shift did not reach OPENED status. Current status: {$lastStatus}.";
    }

    private function pollReceipt(
        IntegrationSetting $setting,
        string $token,
        string $providerReceiptId,
        FiscalizationResult $initialResult,
    ): FiscalizationResult {
        $lastResult = $initialResult;

        foreach ([0, 300_000, 700_000, 1_000_000] as $delayMicroseconds) {
            if ($delayMicroseconds > 0) {
                usleep($delayMicroseconds);
            }

            $lastResult = $this->resultFromResponse(
                $this->authorizedRequest($setting, $token)
                    ->get(self::BaseUrl.'/api/v1/receipts/'.$providerReceiptId),
            );

            if ($lastResult->status !== FiscalReceiptStatus::Processing) {
                return $lastResult;
            }
        }

        return $lastResult;
    }

    private function resultFromResponse(Response $response): FiscalizationResult
    {
        $payload = $this->jsonPayload($response);
        $providerReceiptId = $this->firstString($payload, ['id', 'uuid', 'receipt_id']);
        $providerStatus = $this->firstString($payload, ['status', 'processing_status', 'receipt_status']);

        if (! $response->successful()) {
            return FiscalizationResult::failed(
                $providerReceiptId,
                $providerStatus,
                $this->errorFromPayload($payload) ?? 'Checkbox request failed.',
                $payload,
            );
        }

        $normalizedStatus = strtoupper((string) $providerStatus);
        $fiscalNumber = $this->firstString($payload, [
            'fiscal_code',
            'fiscal_number',
            'fiscal_receipt_number',
            'receipt_number',
        ]);

        if ($fiscalNumber || in_array($normalizedStatus, ['DONE', 'COMPLETED', 'FISCALIZED'], true)) {
            return FiscalizationResult::fiscalized($providerReceiptId, $providerStatus, $fiscalNumber, $payload);
        }

        if (in_array($normalizedStatus, ['ERROR', 'FAILED', 'CANCELLED', 'CANCELED'], true)) {
            return FiscalizationResult::failed(
                $providerReceiptId,
                $providerStatus,
                $this->errorFromPayload($payload) ?? 'Checkbox fiscalization failed.',
                $payload,
            );
        }

        if (in_array($normalizedStatus, self::ProcessingReceiptStatuses, true)) {
            return FiscalizationResult::processing($providerReceiptId, $providerStatus, $payload);
        }

        return FiscalizationResult::processing($providerReceiptId, $providerStatus, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonPayload(Response $response): array
    {
        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function normalizedStatus(array $payload): ?string
    {
        $status = $this->firstString($payload, ['status', 'processing_status', 'receipt_status']);

        return $status ? strtoupper($status) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private function firstString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = Arr::get($payload, $key);

            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_int($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function errorFromPayload(array $payload): ?string
    {
        foreach (['message', 'detail', 'error', 'error_message', 'description'] as $key) {
            $value = Arr::get($payload, $key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $errors = Arr::get($payload, 'errors');

        if (is_array($errors) && $errors !== []) {
            return json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
        }

        return null;
    }
}

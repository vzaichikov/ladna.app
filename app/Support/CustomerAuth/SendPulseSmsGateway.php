<?php

namespace App\Support\CustomerAuth;

use App\Support\SendPulse\SendPulseApiClient;

class SendPulseSmsGateway implements SmsGateway
{
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
        $apiKey = trim((string) ($this->credentials['api_key'] ?? ''));

        if ($apiKey === '') {
            return SmsGatewayResult::failed('SendPulse token is not configured.');
        }

        $payload = [
            'sender' => (string) ($this->credentials['sms_sender'] ?? ''),
            'phones' => [$phone],
            'body' => $message,
        ];

        $response = (new SendPulseApiClient($apiKey))->post('/sms/send', $payload);

        if ($response->successful()) {
            return SmsGatewayResult::sent((string) ($response->json('data.id') ?? ''));
        }

        $message = $response->json('message');

        return SmsGatewayResult::failed(
            is_string($message) && $message !== ''
                ? $message
                : sprintf('SendPulse request failed with HTTP %d.', $response->status()),
        );
    }
}

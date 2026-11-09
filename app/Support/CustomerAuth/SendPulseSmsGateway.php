<?php

namespace App\Support\CustomerAuth;

use App\Support\Sms\SmsGatewayTransport;

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

        $response = (new SmsGatewayTransport)->post('https://api.sendpulse.com/sms/send', $apiKey, $payload);

        if ($response instanceof SmsGatewayResult) {
            return $response;
        }

        if ($response->successful()) {
            $messageId = $response->json('data.id');

            return is_scalar($messageId) && trim((string) $messageId) !== ''
                ? SmsGatewayResult::sent(trim((string) $messageId))
                : SmsGatewayResult::unknown('SendPulse returned no message identifier.');
        }

        $message = $response->json('message');

        return SmsGatewayResult::failed(
            is_string($message) && $message !== ''
                ? $message
                : sprintf('SendPulse request failed with HTTP %d.', $response->status()),
        );
    }
}

<?php

namespace App\Support\CustomerAuth;

use App\Support\Sms\SmsGatewayTransport;

class TurboSmsGateway implements SmsGateway
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
        $response = (new SmsGatewayTransport)->post(
            'https://api.turbosms.ua/message/send.json',
            (string) ($this->credentials['api_token'] ?? ''),
            [
                'recipients' => [$phone],
                'sms' => [
                    'sender' => (string) ($this->credentials['sms_sender'] ?? ''),
                    'text' => $message,
                ],
            ],
        );

        if ($response instanceof SmsGatewayResult) {
            return $response;
        }

        if ($response->successful()) {
            $messageId = $response->json('response_result.0.message_id');

            return is_scalar($messageId) && trim((string) $messageId) !== ''
                ? SmsGatewayResult::sent(trim((string) $messageId))
                : SmsGatewayResult::unknown('TurboSMS returned no message identifier.');
        }

        return SmsGatewayResult::failed($response->body() ?: 'TurboSMS request failed.');
    }
}

<?php

namespace App\Support\CustomerAuth;

use Illuminate\Support\Facades\Http;

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
        $response = Http::withToken((string) ($this->credentials['api_token'] ?? ''))
            ->acceptJson()
            ->timeout(10)
            ->retry(1, 200)
            ->post('https://api.turbosms.ua/message/send.json', [
                'recipients' => [$phone],
                'sms' => [
                    'sender' => (string) ($this->credentials['sms_sender'] ?? ''),
                    'text' => $message,
                ],
            ]);

        if ($response->successful()) {
            return SmsGatewayResult::sent((string) ($response->json('response_result.0.message_id') ?? ''));
        }

        return SmsGatewayResult::failed($response->body() ?: 'TurboSMS request failed.');
    }
}

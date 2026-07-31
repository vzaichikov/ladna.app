<?php

namespace App\Support\CustomerAuth;

use Illuminate\Http\Client\ConnectionException;
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
        try {
            $response = Http::withToken((string) ($this->credentials['api_token'] ?? ''))
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(10)
                ->post('https://api.turbosms.ua/message/send.json', [
                    'recipients' => [$phone],
                    'sms' => [
                        'sender' => (string) ($this->credentials['sms_sender'] ?? ''),
                        'text' => $message,
                    ],
                ]);
        } catch (ConnectionException) {
            return SmsGatewayResult::unknown('TurboSMS request outcome is unknown.');
        }

        if ($response->successful()) {
            return SmsGatewayResult::sent((string) ($response->json('response_result.0.message_id') ?? ''));
        }

        if ($response->serverError()) {
            return SmsGatewayResult::unknown(sprintf(
                'TurboSMS request outcome is unknown after HTTP %d.',
                $response->status(),
            ));
        }

        return SmsGatewayResult::failed($response->body() ?: 'TurboSMS request failed.');
    }
}

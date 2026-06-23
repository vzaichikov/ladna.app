<?php

namespace App\Support\CustomerAuth;

use Illuminate\Support\Facades\Http;

class SmsClubGateway implements SmsGateway
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function __construct(private array $credentials) {}

    public function sendOtp(string $phone, string $message): SmsGatewayResult
    {
        $payload = [
            'phone' => [$phone],
            'message' => $message,
            'src_addr' => (string) ($this->credentials['src_addr'] ?? ''),
        ];

        if (filled($this->credentials['integration_id'] ?? null)) {
            $payload['integration_id'] = (string) $this->credentials['integration_id'];
        }

        $response = Http::withToken((string) ($this->credentials['bearer_token'] ?? ''))
            ->acceptJson()
            ->timeout(10)
            ->retry(1, 200)
            ->post('https://im.smsclub.mobi/sms/send', $payload);

        if ($response->successful()) {
            return SmsGatewayResult::sent((string) ($response->json('info.0.id') ?? ''));
        }

        return SmsGatewayResult::failed($response->body() ?: 'Smsclub request failed.');
    }
}

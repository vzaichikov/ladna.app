<?php

namespace App\Support\CustomerAuth;

use Illuminate\Support\Facades\Http;

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
        $token = $this->accessToken();

        if (! $token) {
            return SmsGatewayResult::failed('SendPulse token is not configured.');
        }

        $payload = [
            'sender' => (string) ($this->credentials['sms_sender'] ?? ''),
            'phones' => [$phone],
            'body' => $message,
        ];

        if (filled($this->credentials['sms_route'] ?? null)) {
            $payload['route'] = (string) $this->credentials['sms_route'];
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(10)
            ->retry(1, 200)
            ->post('https://api.sendpulse.com/sms/send', $payload);

        if ($response->successful()) {
            return SmsGatewayResult::sent((string) ($response->json('data.id') ?? ''));
        }

        return SmsGatewayResult::failed($response->body() ?: 'SendPulse request failed.');
    }

    private function accessToken(): ?string
    {
        if (($this->credentials['auth_mode'] ?? 'api_key') === 'api_key') {
            return filled($this->credentials['api_key'] ?? null) ? (string) $this->credentials['api_key'] : null;
        }

        $response = Http::acceptJson()
            ->timeout(10)
            ->retry(1, 200)
            ->post('https://api.sendpulse.com/oauth/access_token', [
                'grant_type' => 'client_credentials',
                'client_id' => (string) ($this->credentials['client_id'] ?? ''),
                'client_secret' => (string) ($this->credentials['client_secret'] ?? ''),
            ]);

        if (! $response->successful()) {
            return null;
        }

        return $response->json('access_token');
    }
}

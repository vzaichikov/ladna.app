<?php

namespace App\Support\CustomerAuth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TurnstileVerifier
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function verify(?string $token, string $ipAddress, array $credentials): bool
    {
        if (blank($token) || blank($credentials['secret_key'] ?? null)) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->retry(1, 100)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => (string) $credentials['secret_key'],
                    'response' => $token,
                    'remoteip' => $ipAddress,
                ]);
        } catch (ConnectionException) {
            return false;
        }

        return $response->successful() && $response->json('success') === true;
    }
}

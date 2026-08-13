<?php

namespace App\Support\Festivals;

use App\Models\FestivalOnlineStream;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class FestivalMediaMtxGateway
{
    public function configured(): bool
    {
        foreach (['api_url', 'public_url', 'obs_server', 'hls_origin_url', 'internal_secret', 'ip_hmac_key'] as $key) {
            if (trim((string) config("services.festival_stream.{$key}")) === '') {
                return false;
            }
        }

        return true;
    }

    /** @return array{publisher_online: bool, readers: int}|null */
    public function status(FestivalOnlineStream $stream): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        $response = $this->request()->get('/v3/paths/get/'.rawurlencode($stream->path));
        if ($response->notFound()) {
            return ['publisher_online' => false, 'readers' => 0];
        }
        $payload = $response->throw()->json();

        return [
            'publisher_online' => (bool) ($payload['ready'] ?? false),
            'readers' => is_array($payload['readers'] ?? null) ? count($payload['readers']) : 0,
        ];
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl($this->apiUrl())
            ->acceptJson()
            ->connectTimeout((int) config('services.festival_stream.connect_timeout', 2))
            ->timeout((int) config('services.festival_stream.timeout', 5));
        $username = (string) config('services.festival_stream.api_username', '');

        return $username !== ''
            ? $request->withBasicAuth($username, (string) config('services.festival_stream.api_password', ''))
            : $request;
    }

    private function apiUrl(): string
    {
        return rtrim((string) config('services.festival_stream.api_url', ''), '/');
    }
}

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

    /** @return array{publisher_online: bool, readers: int, connected_at: ?string, tracks: list<string>}|null */
    public function status(FestivalOnlineStream $stream): ?array
    {
        if ($this->apiUrl() === '') {
            return null;
        }

        $response = $this->request()->get('/v3/paths/get/'.rawurlencode($stream->path));
        if ($response->notFound()) {
            return $this->offlineStatus();
        }
        $payload = $response->throw()->json();
        $sessions = $this->request()->get('/v3/hlssessions/list', ['itemsPerPage' => 10000])->throw()->json('items', []);

        return [
            'publisher_online' => (bool) ($payload['online'] ?? $payload['ready'] ?? false),
            'readers' => collect(is_array($sessions) ? $sessions : [])
                ->where('path', $stream->path)
                ->where('isCDN', false)
                ->count(),
            'connected_at' => $payload['onlineTime'] ?? $payload['readyTime'] ?? null,
            'tracks' => $this->trackCodecs($payload),
        ];
    }

    /** @return array{publisher_online: false, readers: 0, connected_at: null, tracks: list<string>} */
    private function offlineStatus(): array
    {
        return [
            'publisher_online' => false,
            'readers' => 0,
            'connected_at' => null,
            'tracks' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function trackCodecs(array $payload): array
    {
        $tracks = collect(is_array($payload['tracks2'] ?? null) ? $payload['tracks2'] : [])
            ->pluck('codec')
            ->filter(fn (mixed $codec): bool => is_string($codec) && $codec !== '')
            ->values()
            ->all();

        return $tracks !== []
            ? $tracks
            : collect(is_array($payload['tracks'] ?? null) ? $payload['tracks'] : [])->filter('is_string')->values()->all();
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

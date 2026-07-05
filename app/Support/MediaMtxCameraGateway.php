<?php

namespace App\Support;

use App\Models\Room;
use App\Models\ServiceRoom;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class MediaMtxCameraGateway
{
    public function configured(): bool
    {
        return $this->apiUrl() !== '' && $this->publicUrl() !== '';
    }

    public function ensurePath(Room|ServiceRoom $camera): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('MediaMTX camera gateway is not configured.');
        }

        $rtspUrl = $camera->rtsp_url;

        if (! is_string($rtspUrl) || trim($rtspUrl) === '') {
            throw new RuntimeException('RTSP URL is not configured.');
        }

        $pathName = $this->pathName($camera);
        $response = $this->request()->get('/v3/config/paths/get/'.$pathName);

        if ($response->notFound()) {
            $this->request()
                ->post('/v3/config/paths/add/'.$pathName, $this->pathPayload($rtspUrl))
                ->throw();

            return;
        }

        $response->throw();

        $this->request()
            ->patch('/v3/config/paths/patch/'.$pathName, $this->pathPayload($rtspUrl))
            ->throw();
    }

    public function pathName(Room|ServiceRoom $camera): string
    {
        if ($camera instanceof ServiceRoom) {
            $signature = substr(hash_hmac('sha256', $camera->account_id.':service-room:'.$camera->id, (string) config('app.key')), 0, 16);

            return 'ladna-a'.$camera->account_id.'-sr'.$camera->id.'-'.$signature;
        }

        $signature = substr(hash_hmac('sha256', $camera->account_id.':'.$camera->id, (string) config('app.key')), 0, 16);

        return 'ladna-a'.$camera->account_id.'-r'.$camera->id.'-'.$signature;
    }

    public function playerUrl(Room|ServiceRoom $camera): string
    {
        $prefix = $this->playback() === 'webrtc'
            ? $this->webrtcPrefix()
            : $this->hlsPrefix();

        return Str::finish($this->publicUrl(), '/')
            .ltrim($prefix, '/').'/'
            .rawurlencode($this->pathName($camera)).'/';
    }

    public function playback(): string
    {
        $playback = strtolower((string) config('services.mediamtx.playback', 'webrtc'));

        return in_array($playback, ['hls', 'webrtc'], true) ? $playback : 'webrtc';
    }

    /**
     * @return array<string, bool|string>
     */
    private function pathPayload(string $rtspUrl): array
    {
        return [
            'source' => trim($rtspUrl),
            'sourceOnDemand' => (bool) config('services.mediamtx.source_on_demand', true),
            'sourceOnDemandStartTimeout' => (string) config('services.mediamtx.source_on_demand_start_timeout', '20s'),
            'sourceOnDemandCloseAfter' => (string) config('services.mediamtx.source_on_demand_close_after', '30s'),
            'rtspTransport' => (string) config('services.mediamtx.rtsp_transport', 'tcp'),
        ];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->apiUrl())
            ->acceptJson()
            ->asJson()
            ->connectTimeout(2)
            ->timeout(5);
    }

    private function apiUrl(): string
    {
        return rtrim((string) config('services.mediamtx.api_url', ''), '/');
    }

    private function publicUrl(): string
    {
        return rtrim((string) config('services.mediamtx.public_url', ''), '/');
    }

    private function hlsPrefix(): string
    {
        return trim((string) config('services.mediamtx.hls_prefix', '/hls'), '/');
    }

    private function webrtcPrefix(): string
    {
        return trim((string) config('services.mediamtx.webrtc_prefix', '/webrtc'), '/');
    }
}

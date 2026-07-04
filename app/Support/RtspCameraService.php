<?php

namespace App\Support;

use App\Models\Room;
use Illuminate\Http\StreamedResponse;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class RtspCameraService
{
    public const TEST_TIMEOUT_SECONDS = 35;

    private const STREAM_BOUNDARY = 'ladna-camera-frame';

    /**
     * @return array{ok: bool, message: string}
     */
    public function test(string $url, int $timeoutSeconds = self::TEST_TIMEOUT_SECONDS): array
    {
        $startedAt = microtime(true);
        $endpoint = $this->endpoint($url);

        if ($endpoint === null) {
            return [
                'ok' => false,
                'message' => __('app.rtsp_camera_test_invalid'),
            ];
        }

        $socket = @stream_socket_client(
            $endpoint['transport'].'://'.$this->socketHost($endpoint['host']).':'.$endpoint['port'],
            $errorCode,
            $errorMessage,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
        );

        $elapsed = number_format(microtime(true) - $startedAt, 1);

        if (is_resource($socket)) {
            fclose($socket);

            return [
                'ok' => true,
                'message' => __('app.rtsp_camera_test_success', [
                    'host' => $endpoint['host'],
                    'port' => $endpoint['port'],
                    'seconds' => $elapsed,
                ]),
            ];
        }

        return [
            'ok' => false,
            'message' => __('app.rtsp_camera_test_failed', [
                'host' => $endpoint['host'],
                'port' => $endpoint['port'],
                'seconds' => $elapsed,
                'error' => $errorMessage !== '' ? $errorMessage : (string) $errorCode,
            ]),
        ];
    }

    public function ffmpegAvailable(): bool
    {
        return $this->ffmpegPath() !== null;
    }

    public function stream(Room $room): StreamedResponse
    {
        $ffmpeg = $this->ffmpegPath();

        if ($ffmpeg === null) {
            throw new RuntimeException('ffmpeg is not available.');
        }

        $url = $room->rtsp_url;

        if (! is_string($url) || trim($url) === '') {
            throw new RuntimeException('RTSP URL is not configured.');
        }

        $process = new Process([
            $ffmpeg,
            '-hide_banner',
            '-nostdin',
            '-loglevel',
            'error',
            '-rtsp_transport',
            'tcp',
            '-timeout',
            '10000000',
            '-i',
            $url,
            '-an',
            '-vf',
            'fps=5',
            '-q:v',
            '7',
            '-f',
            'mpjpeg',
            '-boundary_tag',
            self::STREAM_BOUNDARY,
            'pipe:1',
        ]);
        $process->setTimeout(null);
        $process->setIdleTimeout(45);

        return response()->stream(function () use ($process): void {
            $process->start();

            try {
                foreach ($process as $type => $data) {
                    if ($type !== Process::OUT) {
                        continue;
                    }

                    echo $data;

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }

                    flush();

                    if (connection_aborted()) {
                        break;
                    }
                }
            } finally {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
        }, 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Content-Type' => 'multipart/x-mixed-replace; boundary='.self::STREAM_BOUNDARY,
            'Pragma' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @return array{transport: string, host: string, port: int}|null
     */
    private function endpoint(string $url): ?array
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['rtsp', 'rtsps'], true) || $host === '') {
            return null;
        }

        return [
            'transport' => $scheme === 'rtsps' ? 'ssl' : 'tcp',
            'host' => $host,
            'port' => (int) ($parts['port'] ?? ($scheme === 'rtsps' ? 322 : 554)),
        ];
    }

    private function socketHost(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return '['.$host.']';
        }

        return $host;
    }

    private function ffmpegPath(): ?string
    {
        return (new ExecutableFinder)->find('ffmpeg');
    }
}

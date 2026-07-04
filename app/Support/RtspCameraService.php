<?php

namespace App\Support;

class RtspCameraService
{
    public const TEST_TIMEOUT_SECONDS = 35;

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
}

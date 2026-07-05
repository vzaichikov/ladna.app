<?php

namespace App\Support\PeopleCounter;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

class PeopleCounterFrameCapture
{
    public function capture(string $pathName, string $storagePath): PeopleCounterCaptureResult
    {
        $disk = Storage::disk('local');
        $directory = dirname($storagePath);

        if ($directory !== '.') {
            $disk->makeDirectory($directory);
        }

        $absolutePath = $disk->path($storagePath);
        $streamUrl = $this->streamUrl($pathName);
        $command = [
            $this->ffmpegBinary(),
            '-hide_banner',
            '-loglevel',
            'error',
            '-i',
            $streamUrl,
            '-frames:v',
            '1',
            '-q:v',
            '2',
            '-y',
            $absolutePath,
        ];

        if (str_starts_with($streamUrl, 'rtsp://') || str_starts_with($streamUrl, 'rtsps://')) {
            array_splice($command, 4, 0, [
                '-rtsp_transport',
                (string) config('services.mediamtx.rtsp_transport', 'tcp'),
            ]);
        }

        $process = new Process($command, base_path());
        $process->setTimeout($this->timeoutSeconds());
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'Unable to capture camera frame.');
        }

        $dimensions = @getimagesize($absolutePath);

        if (! is_array($dimensions) || ! isset($dimensions[0], $dimensions[1])) {
            $disk->delete($storagePath);

            throw new RuntimeException('Captured camera frame is not a readable image.');
        }

        return new PeopleCounterCaptureResult(
            path: $storagePath,
            width: (int) $dimensions[0],
            height: (int) $dimensions[1],
        );
    }

    private function streamUrl(string $pathName): string
    {
        $template = trim((string) config('services.mediamtx.capture_url_template', ''));

        if ($template !== '') {
            return str_replace('{path}', rawurlencode($pathName), $template);
        }

        return rtrim((string) config('services.mediamtx.rtsp_url', 'rtsp://127.0.0.1:8554'), '/').'/'.$pathName;
    }

    private function timeoutSeconds(): int
    {
        return max(5, (int) config('services.people_counter.capture_timeout', 20));
    }

    private function ffmpegBinary(): string
    {
        $binary = trim((string) config('services.people_counter.ffmpeg_binary', 'ffmpeg'));

        return $binary !== '' ? $binary : 'ffmpeg';
    }
}

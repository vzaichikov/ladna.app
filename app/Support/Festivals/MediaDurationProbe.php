<?php

namespace App\Support\Festivals;

use RuntimeException;
use Symfony\Component\Process\Process;

class MediaDurationProbe
{
    public function seconds(string $path): int
    {
        $binary = trim((string) config('services.voice_recognition.ffprobe_binary', 'ffprobe')) ?: 'ffprobe';
        $process = new Process([$binary, '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $path]);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful() || ! is_numeric(trim($process->getOutput()))) {
            throw new RuntimeException('Unable to detect media duration.');
        }

        return (int) ceil((float) trim($process->getOutput()));
    }
}

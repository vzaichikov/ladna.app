<?php

namespace App\Support\Ai\Voice;

use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class VoiceAudioNormalizer
{
    public const MaxBytes = 25 * 1024 * 1024;

    public const MaxDurationSeconds = 120;

    public function normalize(string $audioContents): NormalizedVoiceAudio
    {
        $size = strlen($audioContents);

        if ($size === 0) {
            throw new VoiceTranscriptionException('empty_audio');
        }

        if ($size > self::MaxBytes) {
            throw new VoiceTranscriptionException('audio_too_large');
        }

        $inputPath = tempnam(sys_get_temp_dir(), 'ladna-voice-');

        if (! is_string($inputPath)) {
            throw new VoiceTranscriptionException('invalid_audio');
        }

        $outputPath = tempnam(sys_get_temp_dir(), 'ladna-voice-normalized-');

        if (! is_string($outputPath)) {
            $this->deleteTemporaryFile($inputPath, 'input');

            throw new VoiceTranscriptionException('invalid_audio');
        }

        @chmod($inputPath, 0600);
        @chmod($outputPath, 0600);
        $normalizedAudio = null;

        try {
            if (file_put_contents($inputPath, $audioContents, LOCK_EX) !== $size) {
                throw new VoiceTranscriptionException('invalid_audio');
            }

            $duration = $this->durationSeconds($inputPath);

            if ($duration > self::MaxDurationSeconds) {
                throw new VoiceTranscriptionException('audio_too_long');
            }

            $this->convertToMp3($inputPath, $outputPath);

            $outputSize = @filesize($outputPath);

            if (! is_int($outputSize) || $outputSize < 1 || $outputSize > self::MaxBytes) {
                throw new VoiceTranscriptionException('invalid_audio');
            }

            $normalizedAudio = new NormalizedVoiceAudio($outputPath);

            return $normalizedAudio;
        } catch (VoiceTranscriptionException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw new VoiceTranscriptionException('invalid_audio', $throwable);
        } finally {
            $this->deleteTemporaryFile($inputPath, 'input');

            if ($normalizedAudio === null) {
                $this->deleteTemporaryFile($outputPath, 'normalized');
            }
        }
    }

    private function durationSeconds(string $inputPath): float
    {
        $result = Process::timeout(10)->run([
            $this->ffprobeBinary(),
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $inputPath,
        ]);

        $duration = trim($result->output());

        if ($result->failed() || ! is_numeric($duration) || (float) $duration <= 0) {
            throw new VoiceTranscriptionException('invalid_audio');
        }

        return (float) $duration;
    }

    private function convertToMp3(string $inputPath, string $outputPath): void
    {
        $result = Process::timeout(60)->run([
            $this->ffmpegBinary(),
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-i',
            $inputPath,
            '-map',
            '0:a:0',
            '-vn',
            '-ac',
            '1',
            '-ar',
            '16000',
            '-codec:a',
            'libmp3lame',
            '-b:a',
            '64k',
            '-f',
            'mp3',
            '-y',
            $outputPath,
        ]);

        if ($result->failed()) {
            throw new VoiceTranscriptionException('invalid_audio');
        }
    }

    private function ffmpegBinary(): string
    {
        $binary = trim((string) config('services.voice_recognition.ffmpeg_binary', 'ffmpeg'));

        return $binary !== '' ? $binary : 'ffmpeg';
    }

    private function ffprobeBinary(): string
    {
        $binary = trim((string) config('services.voice_recognition.ffprobe_binary', 'ffprobe'));

        return $binary !== '' ? $binary : 'ffprobe';
    }

    private function deleteTemporaryFile(string $path, string $kind): void
    {
        if (is_file($path) && ! @unlink($path) && is_file($path) && ! @unlink($path)) {
            report(new RuntimeException("Unable to remove temporary voice {$kind} file."));
        }
    }
}

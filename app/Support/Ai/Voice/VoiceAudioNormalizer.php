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
            '-select_streams',
            'a:0',
            '-read_intervals',
            '%+121',
            '-show_entries',
            'format=duration:stream=index,duration:packet=pts_time,dts_time,duration_time',
            '-of',
            'json',
            $inputPath,
        ]);

        $probe = json_decode($result->output(), true);

        if ($result->failed() || ! is_array($probe)) {
            throw new VoiceTranscriptionException('invalid_audio');
        }

        $streams = $probe['streams'] ?? null;

        if (! is_array($streams) || $streams === []) {
            throw new VoiceTranscriptionException('invalid_audio');
        }

        $durationCandidates = [];
        $formatDuration = $this->finiteNumber($probe['format']['duration'] ?? null);

        if ($formatDuration !== null && $formatDuration > 0) {
            $durationCandidates[] = $formatDuration;
        }

        foreach ($streams as $stream) {
            $streamDuration = is_array($stream)
                ? $this->finiteNumber($stream['duration'] ?? null)
                : null;

            if ($streamDuration !== null && $streamDuration > 0) {
                $durationCandidates[] = $streamDuration;
            }
        }

        $packetStartedAt = null;
        $packetFinishedAt = null;

        foreach ($probe['packets'] ?? [] as $packet) {
            if (! is_array($packet)) {
                continue;
            }

            $packetTimestamp = $this->finiteNumber($packet['pts_time'] ?? null)
                ?? $this->finiteNumber($packet['dts_time'] ?? null);

            if ($packetTimestamp === null) {
                continue;
            }

            $packetDuration = max(0, $this->finiteNumber($packet['duration_time'] ?? null) ?? 0);
            $packetStartedAt = $packetStartedAt === null
                ? $packetTimestamp
                : min($packetStartedAt, $packetTimestamp);
            $packetFinishedAt = $packetFinishedAt === null
                ? $packetTimestamp + $packetDuration
                : max($packetFinishedAt, $packetTimestamp + $packetDuration);
        }

        if ($packetStartedAt !== null && $packetFinishedAt !== null && $packetFinishedAt > $packetStartedAt) {
            $durationCandidates[] = $packetFinishedAt - $packetStartedAt;
        }

        if ($durationCandidates === []) {
            throw new VoiceTranscriptionException('invalid_audio');
        }

        return max($durationCandidates);
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

    private function finiteNumber(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    private function deleteTemporaryFile(string $path, string $kind): void
    {
        if (is_file($path) && ! @unlink($path) && is_file($path) && ! @unlink($path)) {
            report(new RuntimeException("Unable to remove temporary voice {$kind} file."));
        }
    }
}

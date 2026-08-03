<?php

namespace Tests\Unit;

use App\Support\Ai\Voice\VoiceAudioNormalizer;
use App\Support\Ai\Voice\VoiceTranscriptionException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class VoiceAudioNormalizerTest extends TestCase
{
    public function test_it_probes_and_normalizes_audio_with_argument_safe_commands(): void
    {
        Process::preventStrayProcesses();
        $inputPath = null;
        $outputPath = null;
        Process::fake(function (PendingProcess $process) use (&$inputPath, &$outputPath) {
            $command = $process->command;

            if ($command[0] === 'custom-ffprobe') {
                $inputPath = $command[array_key_last($command)];

                return Process::result(output: '120.000000');
            }

            $outputPath = $command[array_key_last($command)];
            file_put_contents($outputPath, 'normalized-mp3');

            return Process::result();
        });
        config([
            'services.voice_recognition.ffprobe_binary' => 'custom-ffprobe',
            'services.voice_recognition.ffmpeg_binary' => 'custom-ffmpeg',
        ]);

        $audio = app(VoiceAudioNormalizer::class)->normalize('source-audio');

        $this->assertSame($outputPath, $audio->path);
        $this->assertFileExists($audio->path);
        $this->assertSame(0600, fileperms($audio->path) & 0777);
        $this->assertIsString($inputPath);
        $this->assertFileDoesNotExist($inputPath);
        Process::assertRan(function (PendingProcess $process): bool {
            return $process->command[0] === 'custom-ffprobe'
                && in_array('format=duration', $process->command, true)
                && $process->timeout === 10;
        });
        Process::assertRan(function (PendingProcess $process): bool {
            return $process->command[0] === 'custom-ffmpeg'
                && in_array('-nostdin', $process->command, true)
                && in_array('0:a:0', $process->command, true)
                && in_array('libmp3lame', $process->command, true)
                && in_array('16000', $process->command, true)
                && $process->timeout === 60;
        });

        $audio->release();

        $this->assertFileDoesNotExist($outputPath);
    }

    public function test_it_rejects_audio_longer_than_two_minutes_before_conversion(): void
    {
        Process::preventStrayProcesses();
        $inputPath = null;
        Process::fake(function (PendingProcess $process) use (&$inputPath) {
            $inputPath = $process->command[array_key_last($process->command)];

            return Process::result(output: '120.001');
        });

        try {
            app(VoiceAudioNormalizer::class)->normalize('source-audio');
            $this->fail('Overlong audio should throw.');
        } catch (VoiceTranscriptionException $exception) {
            $this->assertSame('audio_too_long', $exception->reason());
        }

        $this->assertIsString($inputPath);
        $this->assertFileDoesNotExist($inputPath);
        Process::assertRanTimes(fn (PendingProcess $process): bool => true, times: 1);
    }

    public function test_it_rejects_invalid_media_and_cleans_up_the_temporary_input(): void
    {
        Process::preventStrayProcesses();
        $inputPath = null;
        Process::fake(function (PendingProcess $process) use (&$inputPath) {
            $inputPath = $process->command[array_key_last($process->command)];

            return Process::result(errorOutput: 'sensitive decoder output', exitCode: 1);
        });

        try {
            app(VoiceAudioNormalizer::class)->normalize('not-audio');
            $this->fail('Invalid audio should throw.');
        } catch (VoiceTranscriptionException $exception) {
            $this->assertSame('invalid_audio', $exception->reason());
            $this->assertStringNotContainsString('sensitive decoder output', $exception->getMessage());
        }

        $this->assertIsString($inputPath);
        $this->assertFileDoesNotExist($inputPath);
    }

    public function test_it_cleans_up_both_files_when_conversion_fails(): void
    {
        Process::preventStrayProcesses();
        $inputPath = null;
        $outputPath = null;
        Process::fake(function (PendingProcess $process) use (&$inputPath, &$outputPath) {
            if ($process->command[0] === 'ffprobe') {
                $inputPath = $process->command[array_key_last($process->command)];

                return Process::result(output: '30');
            }

            $outputPath = $process->command[array_key_last($process->command)];
            file_put_contents($outputPath, 'partial-output');

            return Process::result(errorOutput: 'conversion failed', exitCode: 1);
        });

        try {
            app(VoiceAudioNormalizer::class)->normalize('source-audio');
            $this->fail('A failed conversion should throw.');
        } catch (VoiceTranscriptionException $exception) {
            $this->assertSame('invalid_audio', $exception->reason());
        }

        $this->assertIsString($inputPath);
        $this->assertIsString($outputPath);
        $this->assertFileDoesNotExist($inputPath);
        $this->assertFileDoesNotExist($outputPath);
    }

    public function test_it_rejects_empty_and_oversize_payloads_without_starting_a_process(): void
    {
        Process::preventStrayProcesses();
        Process::fake();

        foreach ([
            ['', 'empty_audio'],
            [str_repeat('x', VoiceAudioNormalizer::MaxBytes + 1), 'audio_too_large'],
        ] as [$contents, $reason]) {
            try {
                app(VoiceAudioNormalizer::class)->normalize($contents);
                $this->fail('An invalid payload should throw.');
            } catch (VoiceTranscriptionException $exception) {
                $this->assertSame($reason, $exception->reason());
            }
        }

        Process::assertDidntRun(fn (): bool => true);
    }
}

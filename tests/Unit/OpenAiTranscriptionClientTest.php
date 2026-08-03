<?php

namespace Tests\Unit;

use App\Support\Ai\Voice\OpenAiTranscriptionClient;
use App\Support\Ai\Voice\VoiceTranscriptionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiTranscriptionClientTest extends TestCase
{
    public function test_it_sends_normalized_audio_to_the_openai_transcription_endpoint(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.openai.com/*' => Http::response([
                'text' => '  Привіт, Ладно.  ',
                'usage' => [
                    'type' => 'tokens',
                    'input_tokens' => 12,
                    'output_tokens' => 4,
                    'total_tokens' => 16,
                ],
            ]),
        ]);
        $audioPath = $this->audioFile('normalized-mp3');

        try {
            $result = app(OpenAiTranscriptionClient::class)->transcribe(
                $audioPath,
                'general-openai-key',
                'gpt-transcribe',
            );
        } finally {
            @unlink($audioPath);
        }

        $this->assertSame('Привіт, Ладно.', $result['text']);
        $this->assertSame(16, data_get($result, 'raw.usage.total_tokens'));

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.openai.com/v1/audio/transcriptions'
                && $request->hasHeader('Authorization', 'Bearer general-openai-key')
                && str_contains($request->body(), 'name="model"')
                && str_contains($request->body(), 'gpt-transcribe')
                && str_contains($request->body(), 'name="response_format"')
                && str_contains($request->body(), 'json')
                && str_contains($request->body(), 'name="file"')
                && str_contains($request->body(), 'filename="voice.mp3"')
                && str_contains($request->body(), 'Content-Type: audio/mpeg');
        });
    }

    public function test_it_retries_rate_limits_before_returning_the_transcript(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push(['error' => ['message' => 'Rate limited']], 429)
            ->push(['text' => 'Recovered transcript.']);
        $audioPath = $this->audioFile('normalized-mp3');

        try {
            $result = app(OpenAiTranscriptionClient::class)->transcribe(
                $audioPath,
                'general-openai-key',
                'gpt-transcribe',
            );
        } finally {
            @unlink($audioPath);
        }

        $this->assertSame('Recovered transcript.', $result['text']);
        $this->assertCount(2, Http::recorded());
    }

    public function test_it_retries_a_connection_failure_before_returning_the_transcript(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->pushFailedConnection('Connection failed with sensitive network detail.')
            ->push(['text' => 'Recovered after connection failure.']);
        $audioPath = $this->audioFile('normalized-mp3');

        try {
            $result = app(OpenAiTranscriptionClient::class)->transcribe(
                $audioPath,
                'general-openai-key',
                'gpt-transcribe',
            );
        } finally {
            @unlink($audioPath);
        }

        $this->assertSame('Recovered after connection failure.', $result['text']);
        $this->assertCount(2, Http::recorded());
    }

    public function test_it_does_not_retry_authentication_failures_or_expose_the_response_body(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => ['message' => 'Sensitive upstream detail.'],
            ], 401),
        ]);
        $audioPath = $this->audioFile('normalized-mp3');

        try {
            app(OpenAiTranscriptionClient::class)->transcribe(
                $audioPath,
                'general-openai-key',
                'gpt-transcribe',
            );
            $this->fail('An authentication failure should throw.');
        } catch (VoiceTranscriptionException $exception) {
            $this->assertSame('provider_failed', $exception->reason());
            $this->assertStringNotContainsString('Sensitive upstream detail.', $exception->getMessage());
        } finally {
            @unlink($audioPath);
        }

        $this->assertCount(1, Http::recorded());
    }

    public function test_it_rejects_an_empty_transcript(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.openai.com/*' => Http::response(['text' => '   ']),
        ]);
        $audioPath = $this->audioFile('normalized-mp3');

        try {
            app(OpenAiTranscriptionClient::class)->transcribe(
                $audioPath,
                'general-openai-key',
                'gpt-transcribe',
            );
            $this->fail('An empty transcript should throw.');
        } catch (VoiceTranscriptionException $exception) {
            $this->assertSame('empty_transcript', $exception->reason());
        } finally {
            @unlink($audioPath);
        }
    }

    private function audioFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ladna-openai-audio-test-');

        $this->assertIsString($path);
        file_put_contents($path, $contents);

        return $path;
    }
}

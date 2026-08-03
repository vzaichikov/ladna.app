<?php

namespace App\Support\Ai\Voice;

use App\Enums\VoiceRecognitionProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

class OpenAiTranscriptionClient implements VoiceTranscriptionProvider
{
    /**
     * @return array{text: string, raw: array<string, mixed>}
     */
    public function transcribe(string $audioPath, string $apiKey, string $model): array
    {
        $audioContents = @file_get_contents($audioPath);

        if (! is_string($audioContents) || $audioContents === '') {
            throw new VoiceTranscriptionException('invalid_audio');
        }

        try {
            $response = Http::baseUrl((string) config('services.openai.base_url', 'https://api.openai.com'))
                ->withToken($apiKey)
                ->acceptJson()
                ->attach('file', $audioContents, 'voice.mp3', ['Content-Type' => 'audio/mpeg'])
                ->timeout(90)
                ->connectTimeout(10)
                ->retry(
                    [500, 1000],
                    when: fn (Throwable $throwable): bool => $this->shouldRetry($throwable),
                    throw: false,
                )
                ->post('/v1/audio/transcriptions', [
                    'model' => $model,
                    'response_format' => 'json',
                ]);
        } catch (VoiceTranscriptionException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw new VoiceTranscriptionException('provider_failed', $throwable);
        }

        if ($response->failed()) {
            throw new VoiceTranscriptionException('provider_failed');
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new VoiceTranscriptionException('provider_failed');
        }

        $text = is_string($decoded['text'] ?? null)
            ? trim($decoded['text'])
            : '';

        if ($text === '') {
            throw new VoiceTranscriptionException('empty_transcript');
        }

        return [
            'text' => $text,
            'raw' => $decoded,
        ];
    }

    public function provider(): VoiceRecognitionProvider
    {
        return VoiceRecognitionProvider::OpenAi;
    }

    private function shouldRetry(Throwable $throwable): bool
    {
        if ($throwable instanceof ConnectionException) {
            return true;
        }

        return $throwable instanceof RequestException
            && ($throwable->response->serverError() || $throwable->response->status() === 429);
    }
}

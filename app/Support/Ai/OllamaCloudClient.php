<?php

namespace App\Support\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OllamaCloudClient
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{content: string, raw: array<string, mixed>}
     */
    public function chat(string $apiKey, string $model, array $messages, float $temperature = 0.2, ?string $format = null): array
    {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'options' => [
                'temperature' => $temperature,
            ],
        ];

        if ($format !== null) {
            $payload['format'] = $format;
        }

        $response = Http::baseUrl((string) config('services.ollama_cloud.base_url', 'https://ollama.com'))
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(60)
            ->connectTimeout(10)
            ->retry([500, 1000], throw: false)
            ->post('/api/chat', $payload);

        if ($response->failed()) {
            throw new RuntimeException('Ollama Cloud request failed with status '.$response->status().'.');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Ollama Cloud response is not valid JSON.');
        }

        $content = data_get($payload, 'message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Ollama Cloud response did not include assistant content.');
        }

        return [
            'content' => trim($content),
            'raw' => $payload,
        ];
    }
}

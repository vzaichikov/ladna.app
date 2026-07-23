<?php

namespace App\Support\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OllamaCloudClient
{
    /**
     * @return array<int, string>
     */
    public function models(string $apiKey): array
    {
        $response = Http::baseUrl((string) config('services.ollama_cloud.base_url', 'https://ollama.com'))
            ->withToken($apiKey)
            ->acceptJson()
            ->timeout(20)
            ->connectTimeout(5)
            ->retry([300, 800], throw: false)
            ->get('/api/tags');

        if ($response->failed()) {
            throw new RuntimeException('Ollama Cloud model list request failed with status '.$response->status().'.');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Ollama Cloud model list response is not valid JSON.');
        }

        return collect(data_get($payload, 'models', []))
            ->map(fn (mixed $model): ?string => is_array($model) ? ($model['name'] ?? $model['model'] ?? null) : null)
            ->filter(fn (mixed $model): bool => is_string($model) && $model !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @return array{
     *     content: string,
     *     tool_calls: array<int, array<string, mixed>>,
     *     message: array{role: string, content: string, tool_calls: array<int, array<string, mixed>>},
     *     raw: array<string, mixed>
     * }
     */
    public function chat(
        string $apiKey,
        string $model,
        array $messages,
        float $temperature = 0.2,
        ?string $format = null,
        array $tools = [],
    ): array {
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

        if ($tools !== []) {
            $payload['tools'] = $tools;
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

        $content = data_get($payload, 'message.content', '');
        $toolCalls = collect(data_get($payload, 'message.tool_calls', []))
            ->filter(fn (mixed $toolCall): bool => is_array($toolCall))
            ->map(function (array $toolCall): ?array {
                $name = data_get($toolCall, 'function.name');
                $arguments = data_get($toolCall, 'function.arguments', []);

                if (is_string($arguments)) {
                    $decodedArguments = json_decode($arguments, true);
                    $arguments = is_array($decodedArguments) ? $decodedArguments : [];
                }

                if (! is_string($name) || trim($name) === '' || ! is_array($arguments)) {
                    return null;
                }

                return [
                    'function' => [
                        'name' => trim($name),
                        'arguments' => $arguments,
                    ],
                ];
            })
            ->filter()
            ->values()
            ->all();

        if (! is_string($content) || (trim($content) === '' && $toolCalls === [])) {
            throw new RuntimeException('Ollama Cloud response did not include assistant content.');
        }

        if (is_array($payload['message'] ?? null)) {
            unset($payload['message']['thinking']);
        }

        return [
            'content' => trim($content),
            'tool_calls' => $toolCalls,
            'message' => [
                'role' => 'assistant',
                'content' => trim($content),
                'tool_calls' => $toolCalls,
            ],
            'raw' => $payload,
        ];
    }
}

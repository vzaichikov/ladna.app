<?php

namespace App\Support\Ai;

use App\Enums\AiProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiProviderModelDiscovery
{
    public function __construct(private readonly OllamaCloudClient $ollamaCloudClient) {}

    /**
     * @return array<int, string>
     */
    public function models(AiProvider $provider, string $secret): array
    {
        if ($secret === '') {
            return [];
        }

        return match ($provider) {
            AiProvider::OllamaCloud => $this->ollamaCloudClient->models($secret),
            AiProvider::OpenAiApiKey => $this->openAiModels($secret),
            AiProvider::OpenAiDeviceCode => [],
        };
    }

    /**
     * @return array<int, string>
     */
    private function openAiModels(string $apiKey): array
    {
        $response = Http::baseUrl((string) config('services.openai.base_url', 'https://api.openai.com'))
            ->withToken($apiKey)
            ->acceptJson()
            ->timeout(20)
            ->connectTimeout(5)
            ->retry([300, 800], throw: false)
            ->get('/v1/models');

        if ($response->failed()) {
            throw new RuntimeException('OpenAI model list request failed with status '.$response->status().'.');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('OpenAI model list response is not valid JSON.');
        }

        return collect(data_get($payload, 'data', []))
            ->map(fn (mixed $model): ?string => is_array($model) ? ($model['id'] ?? null) : null)
            ->filter(fn (mixed $model): bool => is_string($model) && $model !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}

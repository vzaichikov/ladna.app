<?php

namespace App\Support\Ai\Voice;

use App\Enums\AiProvider;
use App\Enums\VoiceRecognitionProvider;
use App\Models\PlatformAiProviderCredential;

class VoiceTranscriptionCredentialResolver
{
    public function resolve(VoiceRecognitionProvider $provider): string
    {
        if ($provider !== VoiceRecognitionProvider::OpenAi) {
            throw new VoiceTranscriptionException('provider_unavailable');
        }

        $credential = PlatformAiProviderCredential::query()
            ->where('provider', AiProvider::OpenAiApiKey->value)
            ->first();
        $apiKey = $credential?->apiKey();

        if (! $apiKey) {
            throw new VoiceTranscriptionException('missing_openai_api_key');
        }

        return $apiKey;
    }
}

<?php

namespace App\Support\Ai\Voice;

use App\Enums\VoiceRecognitionProvider;

class VoiceTranscriptionProviderResolver
{
    public function __construct(private readonly OpenAiTranscriptionClient $openAiTranscriptionClient) {}

    public function resolve(VoiceRecognitionProvider $provider): VoiceTranscriptionProvider
    {
        return match ($provider) {
            VoiceRecognitionProvider::OpenAi => $this->openAiTranscriptionClient,
            VoiceRecognitionProvider::SelfHosted => throw new VoiceTranscriptionException('provider_unavailable'),
        };
    }
}

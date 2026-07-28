<?php

namespace App\Enums;

enum AiProvider: string
{
    case OpenAiApiKey = 'openai_api_key';
    case OpenAiDeviceCode = 'openai_device_code';
    case OllamaCloud = 'ollama_cloud';

    public function labelKey(): string
    {
        return 'app.ai_provider_'.$this->value;
    }

    public function supportsImageInference(): bool
    {
        return match ($this) {
            self::OpenAiApiKey => true,
            self::OpenAiDeviceCode, self::OllamaCloud => false,
        };
    }
}

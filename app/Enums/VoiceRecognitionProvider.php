<?php

namespace App\Enums;

enum VoiceRecognitionProvider: string
{
    case OpenAi = 'openai';
    case SelfHosted = 'self_hosted';

    public function labelKey(): string
    {
        return 'app.voice_recognition_provider_'.$this->value;
    }
}

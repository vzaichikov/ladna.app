<?php

namespace App\Support\Ai\Voice;

use App\Enums\VoiceRecognitionProvider;

interface VoiceTranscriptionProvider
{
    public function provider(): VoiceRecognitionProvider;

    /**
     * @return array{text: string, raw: array<string, mixed>}
     */
    public function transcribe(string $audioPath, string $apiKey, string $model): array;
}

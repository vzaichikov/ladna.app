<?php

namespace App\Support\Ai\Voice;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;
use Throwable;

class VoiceTranscriptionException extends RuntimeException implements ShouldntReport
{
    public function __construct(private readonly string $voiceReason, ?Throwable $previous = null)
    {
        parent::__construct('Voice transcription could not be completed.', previous: $previous);
    }

    public function reason(): string
    {
        return $this->voiceReason;
    }
}

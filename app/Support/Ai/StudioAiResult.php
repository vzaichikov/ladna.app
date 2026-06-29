<?php

namespace App\Support\Ai;

class StudioAiResult
{
    public function __construct(
        public readonly string $text,
        public readonly bool $usedAi,
        public readonly bool $rejected = false,
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly ?string $fallbackReason = null,
    ) {}

    public static function ai(string $text, string $provider, string $model): self
    {
        return new self(
            text: $text,
            usedAi: true,
            provider: $provider,
            model: $model,
        );
    }

    public static function fallback(string $reason): self
    {
        return new self(
            text: '',
            usedAi: false,
            fallbackReason: $reason,
        );
    }

    public static function rejected(string $text): self
    {
        return new self(
            text: $text,
            usedAi: false,
            rejected: true,
        );
    }
}

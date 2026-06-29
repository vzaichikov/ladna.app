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
        public readonly array $followUpActions = [],
        public readonly array $helpSources = [],
    ) {}

    /**
     * @param  array<int, string>  $followUpActions
     * @param  array<int, array{slug: string, title: string, sections: array<int, string>}>  $helpSources
     */
    public static function ai(string $text, string $provider, string $model, array $followUpActions = [], array $helpSources = []): self
    {
        return new self(
            text: $text,
            usedAi: true,
            provider: $provider,
            model: $model,
            followUpActions: $followUpActions,
            helpSources: $helpSources,
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

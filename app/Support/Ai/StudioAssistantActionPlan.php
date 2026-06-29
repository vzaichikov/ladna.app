<?php

namespace App\Support\Ai;

use App\Models\AiPendingAction;

class StudioAssistantActionPlan
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    private function __construct(
        public readonly bool $handled,
        public readonly ?AiPendingAction $pendingAction = null,
        public readonly ?string $message = null,
        public readonly array $metadata = [],
    ) {}

    public static function none(): self
    {
        return new self(false);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function message(string $message, array $metadata = []): self
    {
        return new self(true, message: $message, metadata: $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function pending(AiPendingAction $pendingAction, ?string $message = null, array $metadata = []): self
    {
        return new self(true, $pendingAction, $message, $metadata);
    }
}

<?php

namespace App\Support\Ai;

use App\Enums\StudioAiDisposition;

class StudioAiResult
{
    public function __construct(
        public readonly string $text,
        public readonly bool $usedAi,
        public readonly StudioAiDisposition $disposition = StudioAiDisposition::Answer,
        public readonly bool $rejected = false,
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly ?string $fallbackReason = null,
        public readonly ?string $fallbackDetail = null,
        public readonly array $followUpActions = [],
        public readonly array $helpSources = [],
        public readonly ?StudioAiActionInput $actionInput = null,
        public readonly ?StudioAiCalendarReference $calendarReference = null,
    ) {}

    /**
     * @param  array<int, string>  $followUpActions
     * @param  array<int, array{slug: string, title: string, sections: array<int, string>}>  $helpSources
     */
    public static function answer(
        string $text,
        string $provider,
        string $model,
        array $followUpActions = [],
        array $helpSources = [],
        ?StudioAiCalendarReference $calendarReference = null,
    ): self {
        return new self(
            text: $text,
            usedAi: true,
            provider: $provider,
            model: $model,
            followUpActions: $followUpActions,
            helpSources: $helpSources,
            calendarReference: $calendarReference,
        );
    }

    public static function action(
        StudioAiDisposition $disposition,
        StudioAiActionInput $actionInput,
        string $provider,
        string $model,
        ?StudioAiCalendarReference $calendarReference = null,
    ): self {
        return new self(
            text: '',
            usedAi: true,
            disposition: $disposition,
            provider: $provider,
            model: $model,
            actionInput: $actionInput,
            calendarReference: $calendarReference,
        );
    }

    public static function fallback(string $reason, ?string $detail = null): self
    {
        return new self(
            text: '',
            usedAi: false,
            fallbackReason: $reason,
            fallbackDetail: $detail,
        );
    }

    public static function rejected(string $text): self
    {
        return new self(
            text: $text,
            usedAi: false,
            disposition: StudioAiDisposition::OutOfScope,
            rejected: true,
        );
    }

    public function isAction(): bool
    {
        return $this->disposition->isAction() && $this->actionInput !== null;
    }
}

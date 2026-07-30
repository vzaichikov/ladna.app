<?php

namespace App\Support\Ai;

use App\Enums\StudioAiDisposition;
use Illuminate\Support\Carbon;

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
        public readonly ?string $limitScope = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?Carbon $blockedUntil = null,
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

    public static function fallback(string $reason, ?string $detail = null, string $text = ''): self
    {
        return new self(
            text: $text !== '' ? $text : self::fallbackText($reason),
            usedAi: false,
            fallbackReason: $reason,
            fallbackDetail: $detail,
        );
    }

    public static function restriction(
        string $text,
        string $reason,
        ?string $limitScope,
        ?int $retryAfterSeconds,
        ?Carbon $blockedUntil,
    ): self {
        return new self(
            text: $text,
            usedAi: false,
            fallbackReason: $reason,
            limitScope: $limitScope,
            retryAfterSeconds: $retryAfterSeconds,
            blockedUntil: $blockedUntil,
        );
    }

    private static function fallbackText(string $reason): string
    {
        return match ($reason) {
            'ai_tool_loop_limit' => __('app.assistant_ai_checks_incomplete'),
            'invalid_ai_action', 'invalid_ai_response' => __('app.assistant_ai_clarify_request'),
            'image_only_action_not_allowed' => __('app.assistant_image_action_needs_text'),
            default => '',
        };
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

    public static function aiRejected(string $text, string $provider, string $model): self
    {
        return new self(
            text: $text,
            usedAi: true,
            disposition: StudioAiDisposition::OutOfScope,
            rejected: true,
            provider: $provider,
            model: $model,
        );
    }

    public function isAction(): bool
    {
        return $this->disposition->isAction() && $this->actionInput !== null;
    }

    public function withRestriction(
        string $text,
        string $reason,
        ?string $limitScope,
        ?int $retryAfterSeconds,
        ?Carbon $blockedUntil,
    ): self {
        return new self(
            text: $text,
            usedAi: $this->usedAi,
            disposition: $this->disposition,
            rejected: $this->rejected,
            provider: $this->provider,
            model: $this->model,
            fallbackReason: $reason,
            fallbackDetail: $this->fallbackDetail,
            followUpActions: $this->followUpActions,
            helpSources: $this->helpSources,
            actionInput: $this->actionInput,
            calendarReference: $this->calendarReference,
            limitScope: $limitScope,
            retryAfterSeconds: $retryAfterSeconds,
            blockedUntil: $blockedUntil,
        );
    }
}

<?php

namespace App\Support\Festivals;

final readonly class FestivalNotificationMessage
{
    /** @param array<int, string> $lines */
    public function __construct(
        public string $subject,
        public string $greeting,
        public array $lines,
        public string $smsText,
        public ?string $actionLabel = null,
        public ?string $actionUrl = null,
    ) {}

    public function emailText(): string
    {
        return implode("\n\n", array_filter([$this->greeting, ...$this->lines, $this->actionUrl]));
    }
}

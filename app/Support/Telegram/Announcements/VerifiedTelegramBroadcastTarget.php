<?php

namespace App\Support\Telegram\Announcements;

final readonly class VerifiedTelegramBroadcastTarget
{
    public function __construct(
        public string $chatId,
        public string $title,
        public string $chatType,
        public string $botStatus,
    ) {}

    public function hash(int $installationId, string $purpose): string
    {
        return hash('sha256', implode("\0", [
            (string) $installationId,
            $purpose,
            $this->chatId,
            $this->title,
            $this->chatType,
        ]));
    }
}

<?php

namespace App\Support\Telegram;

use App\Models\TelegramBotInstallation;
use Illuminate\Http\Client\Response;
use Throwable;

class TelegramStatusMessage
{
    private ?string $messageId = null;

    private ?string $lastText = null;

    public function __construct(
        private readonly TelegramClient $telegramClient,
        private readonly TelegramBotInstallation $installation,
        private readonly string $chatId,
        private readonly string $initialText,
    ) {}

    public function start(): ?Response
    {
        if ($this->chatId === '') {
            return null;
        }

        try {
            $response = $this->telegramClient->sendMessage($this->installation, $this->chatId, $this->initialText);
            $this->messageId = $this->messageIdFromResponse($response);
            $this->lastText = $this->initialText;

            return $response;
        } catch (Throwable $throwable) {
            report($throwable);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function update(string $text, array $extra = []): ?Response
    {
        $text = trim($text);

        if ($text === '' || ! $this->messageId || ($this->lastText === $text && $extra === [])) {
            return null;
        }

        try {
            $response = $this->telegramClient->editMessageText($this->installation, $this->chatId, $this->messageId, $text, $extra);

            if ($this->telegramOk($response)) {
                $this->lastText = $text;
            }

            return $response;
        } catch (Throwable $throwable) {
            report($throwable);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function finalize(string $text, array $extra = []): ?Response
    {
        $response = $this->update($text, $extra);

        if ($this->telegramOk($response)) {
            return $response;
        }

        try {
            $response = $this->telegramClient->sendMessage($this->installation, $this->chatId, $text, $extra);
            $this->messageId = $this->messageIdFromResponse($response);
            $this->lastText = $text;

            return $response;
        } catch (Throwable $throwable) {
            report($throwable);

            return null;
        }
    }

    public function messageId(): ?string
    {
        return $this->messageId;
    }

    private function telegramOk(?Response $response): bool
    {
        return $response?->successful() === true && $response->json('ok') === true;
    }

    private function messageIdFromResponse(?Response $response): ?string
    {
        $messageId = data_get($response?->json(), 'result.message_id');

        return filled($messageId) ? (string) $messageId : null;
    }
}

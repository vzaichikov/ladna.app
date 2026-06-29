<?php

namespace App\Support\Telegram;

use App\Models\TelegramBotInstallation;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class TelegramClient
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function sendMessage(TelegramBotInstallation $installation, string|int $chatId, string $text, array $extra = []): ?Response
    {
        $token = $installation->tokenValue();

        if (! $token) {
            return null;
        }

        return Http::timeout(8)
            ->connectTimeout(3)
            ->retry([100, 300], throw: false)
            ->post($this->methodUrl($token, 'sendMessage'), [
                'chat_id' => $chatId,
                'text' => $text,
                ...$extra,
            ]);
    }

    public function sendChatAction(TelegramBotInstallation $installation, string|int $chatId, string $action = 'typing'): ?Response
    {
        $token = $installation->tokenValue();

        if (! $token || (string) $chatId === '') {
            return null;
        }

        return Http::timeout(8)
            ->connectTimeout(3)
            ->retry([100, 300], throw: false)
            ->post($this->methodUrl($token, 'sendChatAction'), [
                'chat_id' => $chatId,
                'action' => $action,
            ]);
    }

    public function setWebhook(TelegramBotInstallation $installation): ?Response
    {
        $token = $installation->tokenValue();
        $webhookUrl = $installation->webhook_url;

        if (! $token || ! $webhookUrl) {
            return null;
        }

        return Http::timeout(8)
            ->connectTimeout(3)
            ->retry([100, 300], throw: false)
            ->post($this->methodUrl($token, 'setWebhook'), array_filter([
                'url' => $webhookUrl,
                'secret_token' => $installation->webhookSecret(),
                'allowed_updates' => ['message', 'callback_query'],
            ], fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    /**
     * @param  array<int, array{command: string, description: string}>  $commands
     */
    public function setCommands(TelegramBotInstallation $installation, array $commands): ?Response
    {
        $token = $installation->tokenValue();

        if (! $token) {
            return null;
        }

        return Http::timeout(8)
            ->connectTimeout(3)
            ->retry([100, 300], throw: false)
            ->post($this->methodUrl($token, 'setMyCommands'), [
                'commands' => array_values($commands),
            ]);
    }

    public function getWebhookInfo(TelegramBotInstallation $installation): ?Response
    {
        $token = $installation->tokenValue();

        if (! $token) {
            return null;
        }

        return Http::timeout(8)
            ->connectTimeout(3)
            ->retry([100, 300], throw: false)
            ->get($this->methodUrl($token, 'getWebhookInfo'));
    }

    public function answerCallbackQuery(TelegramBotInstallation $installation, string $callbackQueryId): ?Response
    {
        $token = $installation->tokenValue();

        if (! $token || $callbackQueryId === '') {
            return null;
        }

        return Http::timeout(8)
            ->connectTimeout(3)
            ->retry([100, 300], throw: false)
            ->post($this->methodUrl($token, 'answerCallbackQuery'), [
                'callback_query_id' => $callbackQueryId,
            ]);
    }

    public function deleteWebhook(TelegramBotInstallation $installation): ?Response
    {
        $token = $installation->tokenValue();

        if (! $token) {
            return null;
        }

        return Http::timeout(8)
            ->connectTimeout(3)
            ->retry([100, 300], throw: false)
            ->post($this->methodUrl($token, 'deleteWebhook'));
    }

    private function methodUrl(string $token, string $method): string
    {
        return "https://api.telegram.org/bot{$token}/{$method}";
    }
}

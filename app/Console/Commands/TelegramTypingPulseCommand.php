<?php

namespace App\Console\Commands;

use App\Models\TelegramBotInstallation;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramTypingIndicator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

#[Signature('telegram:typing-pulse {key : Short-lived typing pulse key}')]
#[Description('Refresh a Telegram typing action while a webhook prompt is being processed.')]
class TelegramTypingPulseCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(TelegramClient $telegramClient): int
    {
        $key = (string) $this->argument('key');

        if (preg_match('/^[A-Za-z0-9]{32,64}$/', $key) !== 1) {
            return self::FAILURE;
        }

        $path = TelegramTypingIndicator::pulsePath($key);
        $payload = $this->payload($path);

        if ($payload === null) {
            return self::SUCCESS;
        }

        $installation = TelegramBotInstallation::query()->find((int) ($payload['installation_id'] ?? 0));
        $chatId = (string) ($payload['chat_id'] ?? '');

        if (! $installation || $installation->account?->isReadOnlyDemo() || $chatId === '') {
            $this->deletePulse($path);

            return self::SUCCESS;
        }

        $refreshSeconds = max(1, (int) floor((float) ($payload['refresh_seconds'] ?? 4)));
        $expiresAt = (int) ($payload['expires_at'] ?? (time() + 55));

        while (time() < $expiresAt) {
            sleep($refreshSeconds);
            clearstatcache(true, $path);

            if (! is_file($path)) {
                return self::SUCCESS;
            }

            try {
                $telegramClient->sendChatAction($installation, $chatId);
            } catch (Throwable $throwable) {
                report($throwable);

                return self::FAILURE;
            }
        }

        $this->deletePulse($path);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payload(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $payload = json_decode((string) File::get($path), true);

        return is_array($payload) ? $payload : null;
    }

    private function deletePulse(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

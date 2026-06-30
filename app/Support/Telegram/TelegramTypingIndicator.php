<?php

namespace App\Support\Telegram;

use App\Models\TelegramBotInstallation;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class TelegramTypingIndicator
{
    private ?float $lastSentAt = null;

    private ?string $pulsePath = null;

    private ?Process $pulseProcess = null;

    public function __construct(
        private readonly TelegramClient $telegramClient,
        private readonly TelegramBotInstallation $installation,
        private readonly string $chatId,
        private readonly float $refreshSeconds = 4.0,
        private readonly int $maxSeconds = 55,
    ) {}

    public function __destruct()
    {
        $this->stop();
    }

    public function start(): void
    {
        $this->refresh(force: true);
        $this->startPulse();
    }

    public function refresh(bool $force = false): ?Response
    {
        $now = microtime(true);

        if (! $force && $this->lastSentAt !== null && ($now - $this->lastSentAt) < $this->refreshSeconds) {
            return null;
        }

        $this->lastSentAt = $now;

        try {
            return $this->telegramClient->sendChatAction($this->installation, $this->chatId);
        } catch (Throwable $throwable) {
            report($throwable);

            return null;
        }
    }

    public function stop(): void
    {
        if ($this->pulsePath && is_file($this->pulsePath)) {
            @unlink($this->pulsePath);
        }

        $this->pulsePath = null;
    }

    public static function pulseDirectory(): string
    {
        return storage_path('framework/telegram-typing');
    }

    public static function pulsePath(string $key): string
    {
        return self::pulseDirectory().DIRECTORY_SEPARATOR.$key.'.json';
    }

    private function startPulse(): void
    {
        if (! (bool) config('services.telegram.typing_pulse_enabled', true)
            || app()->runningUnitTests()
            || $this->refreshSeconds <= 0.0
            || $this->chatId === '') {
            return;
        }

        try {
            File::ensureDirectoryExists(self::pulseDirectory());

            $key = Str::random(40);
            $this->pulsePath = self::pulsePath($key);

            File::put($this->pulsePath, json_encode([
                'installation_id' => $this->installation->id,
                'chat_id' => $this->chatId,
                'refresh_seconds' => $this->refreshSeconds,
                'expires_at' => time() + $this->maxSeconds,
            ], JSON_THROW_ON_ERROR));

            $php = (new PhpExecutableFinder)->find(false);

            if (! $php) {
                $this->stop();

                return;
            }

            $process = new Process([$php, base_path('artisan'), 'telegram:typing-pulse', $key], base_path());
            $process->disableOutput();
            $process->setTimeout(null);
            $process->start();

            $this->pulseProcess = $process;
        } catch (Throwable $throwable) {
            report($throwable);

            $this->stop();
        }
    }
}

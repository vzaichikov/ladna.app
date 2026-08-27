<?php

namespace App\Support\Telegram;

use App\Enums\TelegramBotProfile;
use App\Models\FestivalSeries;
use App\Models\TelegramBotInstallation;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

class TelegramWebhookManager
{
    public const StatusSynced = 'webhook_synced';

    public const StatusFailed = 'webhook_failed';

    public const StatusDeleted = 'webhook_deleted';

    public function __construct(private readonly TelegramClient $telegramClient) {}

    public function ownerInstallation(): ?TelegramBotInstallation
    {
        return TelegramBotInstallation::query()
            ->where('scope_type', 'platform')
            ->where('scope_id', 0)
            ->where('profile', TelegramBotProfile::Owner->value)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function status(?TelegramBotInstallation $installation = null): array
    {
        $installation ??= $this->ownerInstallation();

        return [
            'local' => $this->localStatus($installation),
            'telegram' => $this->telegramStatus($installation),
        ];
    }

    /**
     * @return array{ok: bool, message: string, status: array<string, mixed>}
     */
    public function register(?TelegramBotInstallation $installation = null): array
    {
        $installation ??= $this->ownerInstallation();

        if (! $installation || ! $installation->tokenValue() || ! $installation->webhook_url) {
            return [
                'ok' => false,
                'message' => __('app.telegram_webhook_missing_configuration'),
                'status' => $this->status($installation),
            ];
        }

        try {
            $webhookResponse = $this->telegramClient->setWebhook($installation);
            $webhookOk = $this->telegramOk($webhookResponse);
            $commandResponses = $webhookOk ? $this->registerCommands($installation) : [];
            $commandsOk = $webhookOk && collect($commandResponses)->every(
                fn (?Response $response): bool => $this->telegramOk($response),
            );
            $menuResponse = $webhookOk && $commandsOk ? $this->registerMenuButton($installation) : null;
            $menuOk = $installation->profile !== TelegramBotProfile::Festival || $this->telegramOk($menuResponse);
            $ok = $webhookOk && $commandsOk && $menuOk;

            $installation->forceFill([
                'status' => $ok ? self::StatusSynced : self::StatusFailed,
                'last_webhook_synced_at' => $ok ? now() : $installation->last_webhook_synced_at,
            ])->save();

            return [
                'ok' => $ok,
                'message' => match (true) {
                    $ok => __('app.telegram_webhook_registered'),
                    ! $webhookOk => $this->telegramError($webhookResponse, __('app.telegram_webhook_registration_failed')),
                    ! $commandsOk => $this->telegramError(
                        collect($commandResponses)->first(fn (?Response $response): bool => ! $this->telegramOk($response)),
                        __('app.telegram_bot_commands_registration_failed'),
                    ),
                    default => $this->telegramError($menuResponse, __('app.festival_telegram_menu_registration_failed')),
                },
                'status' => $this->status($installation->fresh()),
            ];
        } catch (Throwable $throwable) {
            $this->reportRequestFailure($throwable, 'registration');

            $installation->forceFill(['status' => self::StatusFailed])->save();

            return [
                'ok' => false,
                'message' => __('app.telegram_webhook_registration_failed'),
                'status' => $this->status($installation->fresh()),
            ];
        }
    }

    /**
     * @return array{ok: bool, message: string, status: array<string, mixed>}
     */
    public function delete(?TelegramBotInstallation $installation = null): array
    {
        $installation ??= $this->ownerInstallation();

        if (! $installation || ! $installation->tokenValue()) {
            return [
                'ok' => false,
                'message' => __('app.telegram_webhook_missing_configuration'),
                'status' => $this->status($installation),
            ];
        }

        try {
            $response = $this->telegramClient->deleteWebhook($installation);
            $ok = $this->telegramOk($response);

            $installation->forceFill([
                'status' => $ok ? self::StatusDeleted : self::StatusFailed,
                'last_webhook_synced_at' => $ok ? null : $installation->last_webhook_synced_at,
            ])->save();

            return [
                'ok' => $ok,
                'message' => $ok
                    ? __('app.telegram_webhook_deleted')
                    : $this->telegramError($response, __('app.telegram_webhook_delete_failed')),
                'status' => $this->status($installation->fresh()),
            ];
        } catch (Throwable $throwable) {
            $this->reportRequestFailure($throwable, 'deletion');

            $installation->forceFill(['status' => self::StatusFailed])->save();

            return [
                'ok' => false,
                'message' => __('app.telegram_webhook_delete_failed'),
                'status' => $this->status($installation->fresh()),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function localStatus(?TelegramBotInstallation $installation): array
    {
        if (! $installation) {
            return [
                'configured' => false,
                'enabled' => false,
                'status' => 'missing',
                'status_label' => __('app.telegram_not_checked'),
                'bot_username' => null,
                'has_token' => false,
                'token_last_four' => null,
                'webhook_url' => null,
                'last_webhook_synced_at' => null,
            ];
        }

        return [
            'configured' => (bool) $installation->tokenValue(),
            'enabled' => (bool) $installation->is_enabled,
            'status' => $installation->status,
            'status_label' => __('app.'.$installation->status),
            'bot_id' => $installation->bot_id,
            'bot_username' => $installation->bot_username,
            'has_token' => (bool) $installation->tokenValue(),
            'token_last_four' => $installation->token_last_four,
            'webhook_url' => $installation->webhook_url,
            'last_webhook_synced_at' => $installation->last_webhook_synced_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function telegramStatus(?TelegramBotInstallation $installation): array
    {
        if (! $installation || ! $installation->tokenValue()) {
            return [
                'checked' => false,
                'ok' => false,
                'message' => __('app.telegram_webhook_missing_configuration'),
                'url' => null,
                'is_registered' => false,
                'url_matches' => false,
                'pending_update_count' => null,
                'last_error_date' => null,
                'last_error_message' => null,
                'allowed_updates' => [],
            ];
        }

        try {
            $response = $this->telegramClient->getWebhookInfo($installation);
            $payload = $response?->json() ?? [];
            $result = is_array(data_get($payload, 'result')) ? data_get($payload, 'result') : [];
            $url = (string) data_get($result, 'url', '');

            return [
                'checked' => true,
                'ok' => $this->telegramOk($response),
                'message' => $this->telegramOk($response) ? null : $this->telegramError($response, __('app.telegram_webhook_status_failed')),
                'url' => $url !== '' ? $url : null,
                'is_registered' => $url !== '',
                'url_matches' => $url !== '' && hash_equals((string) $installation->webhook_url, $url),
                'pending_update_count' => data_get($result, 'pending_update_count'),
                'last_error_date' => data_get($result, 'last_error_date'),
                'last_error_message' => data_get($result, 'last_error_message'),
                'allowed_updates' => data_get($result, 'allowed_updates', []),
            ];
        } catch (Throwable $throwable) {
            $this->reportRequestFailure($throwable, 'status check');

            return [
                'checked' => true,
                'ok' => false,
                'message' => __('app.telegram_webhook_status_failed'),
                'url' => null,
                'is_registered' => false,
                'url_matches' => false,
                'pending_update_count' => null,
                'last_error_date' => null,
                'last_error_message' => null,
                'allowed_updates' => [],
            ];
        }
    }

    private function telegramOk(?Response $response): bool
    {
        return $response?->successful() === true && ($response->json('ok') === true);
    }

    private function telegramError(?Response $response, string $fallback): string
    {
        $description = $response?->json('description');

        if (is_string($description) && $description !== '') {
            return $description;
        }

        if ($response) {
            return $fallback.' (HTTP '.$response->status().')';
        }

        return $fallback;
    }

    private function reportRequestFailure(Throwable $throwable, string $operation): void
    {
        report(new RuntimeException('Telegram API '.$operation.' failed ('.$throwable::class.').'));
    }

    /**
     * @return array<int, array{command: string, description: string}>
     */
    private function ownerCommands(): array
    {
        return [
            [
                'command' => 'book',
                'description' => __('app.telegram_command_book_description'),
            ],
            [
                'command' => 'cancel_booking',
                'description' => __('app.telegram_command_cancel_booking_description'),
            ],
            [
                'command' => 'restart',
                'description' => __('app.telegram_command_restart_description'),
            ],
        ];
    }

    /**
     * @return array<int, Response|null>
     */
    private function registerCommands(TelegramBotInstallation $installation): array
    {
        if ($installation->profile === TelegramBotProfile::Customer) {
            return collect(['uk', 'en'])
                ->map(fn (string $locale): ?Response => $this->telegramClient->setCommands(
                    $installation,
                    $this->customerCommands($locale),
                    $locale,
                ))
                ->all();
        }

        if ($installation->profile === TelegramBotProfile::Festival) {
            return collect(['uk', 'en'])
                ->map(fn (string $locale): ?Response => $this->telegramClient->setCommands(
                    $installation,
                    $this->festivalCommands($locale),
                    $locale,
                ))
                ->all();
        }

        return [$this->telegramClient->setCommands($installation, $this->ownerCommands())];
    }

    /**
     * @return array<int, array{command: string, description: string}>
     */
    private function customerCommands(string $locale): array
    {
        return collect([
            'start',
            'book',
            'bookings',
            'passes',
            'attendance',
            'studio',
            'language',
            'unlink',
            'cancel',
        ])->map(fn (string $command): array => [
            'command' => $command,
            'description' => __('app.telegram_customer_command_'.$command, [], $locale),
        ])->all();
    }

    /**
     * @return array<int, array{command: string, description: string}>
     */
    private function festivalCommands(string $locale): array
    {
        return collect(['start', 'festival', 'unlink'])
            ->map(fn (string $command): array => [
                'command' => $command,
                'description' => __('app.festival_telegram_command_'.$command, [], $locale),
            ])->all();
    }

    private function registerMenuButton(TelegramBotInstallation $installation): ?Response
    {
        if ($installation->profile !== TelegramBotProfile::Festival) {
            return null;
        }

        $series = FestivalSeries::query()
            ->whereKey($installation->scope_id)
            ->where('account_id', $installation->account_id)
            ->first();

        if (! $series) {
            return null;
        }

        return $this->telegramClient->setChatMenuButton(
            $installation,
            __('app.festival_telegram_open_app', locale: $series->account()->value('default_language')),
            route('public.festival-telegram.show', [$series->account()->value('slug'), $series->slug]),
        );
    }
}

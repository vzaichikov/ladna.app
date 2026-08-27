<?php

namespace App\Support\Telegram;

use App\Enums\FestivalNotificationChannel;
use App\Enums\FestivalNotificationStatus;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\Account;
use App\Models\FestivalNotification;
use App\Models\FestivalSeries;
use App\Models\TelegramBotInstallation;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class FestivalTelegramBotConnector
{
    public function __construct(
        private readonly TelegramClient $telegramClient,
        private readonly TelegramWebhookManager $webhooks,
    ) {}

    /** @return array{ok: bool, message: string} */
    public function connect(Account $account, FestivalSeries $series, ?string $newToken): array
    {
        $this->assertSeries($account, $series);
        $installation = $this->installationFor($series);
        $token = filled($newToken) ? trim((string) $newToken) : $installation->tokenValue();

        if (! $token) {
            return ['ok' => false, 'message' => __('app.telegram_webhook_missing_configuration')];
        }

        try {
            $candidate = new TelegramBotInstallation(['encrypted_token' => $token]);
            $identityResponse = $this->telegramClient->getMe($candidate);

            if (! $this->telegramOk($identityResponse)) {
                return ['ok' => false, 'message' => $this->telegramError($identityResponse, __('app.telegram_bot_identity_failed'))];
            }

            $botId = (string) $identityResponse?->json('result.id', '');
            $botUsername = ltrim((string) $identityResponse?->json('result.username', ''), '@');

            if ($botId === '' || $botUsername === '') {
                return ['ok' => false, 'message' => __('app.telegram_bot_identity_failed')];
            }

            $duplicateExists = TelegramBotInstallation::query()
                ->where('bot_id', $botId)
                ->when($installation->exists, fn ($query) => $query->where('id', '!=', $installation->id))
                ->exists();

            if ($duplicateExists) {
                return ['ok' => false, 'message' => __('app.telegram_bot_already_connected')];
            }

            $oldBotId = (string) $installation->bot_id;
            $oldToken = (string) $installation->tokenValue();
            $tokenChanged = filled($newToken) && ($oldToken === '' || ! hash_equals($oldToken, $token));

            if ($tokenChanged && $oldToken !== '') {
                $this->webhooks->delete($installation);
            }

            if (! $installation->exists || $tokenChanged || ! $installation->webhookKey()) {
                $this->rotateWebhookCredentials($installation, $series);
            }

            $installation->fill([
                'account_id' => $account->id,
                'scope_type' => 'festival_series',
                'scope_id' => $series->id,
                'profile' => TelegramBotProfile::Festival->value,
                'bot_id' => $botId,
                'bot_username' => $botUsername,
                'encrypted_token' => $token,
                'token_last_four' => Str::substr($token, -4),
                'status' => 'configured',
                'is_enabled' => false,
            ])->save();

            if ($oldBotId !== '' && ! hash_equals($oldBotId, $botId)) {
                $this->revokeAuthorizations($installation);
            }

            $result = $this->webhooks->register($installation->fresh());
            $installation->refresh()->forceFill(['is_enabled' => (bool) $result['ok']])->save();

            return ['ok' => (bool) $result['ok'], 'message' => (string) $result['message']];
        } catch (Throwable $throwable) {
            report(new RuntimeException('Festival Telegram bot connection failed ('.$throwable::class.').'));

            return ['ok' => false, 'message' => __('app.telegram_webhook_registration_failed')];
        }
    }

    /** @return array{ok: bool, message: string} */
    public function check(Account $account, FestivalSeries $series): array
    {
        $installation = $this->existingInstallationFor($account, $series);

        if (! $installation || ! $installation->tokenValue()) {
            return ['ok' => false, 'message' => __('app.telegram_webhook_missing_configuration')];
        }

        $status = $this->webhooks->status($installation);
        $telegram = (array) $status['telegram'];
        $ok = (bool) ($telegram['ok'] ?? false)
            && (bool) ($telegram['is_registered'] ?? false)
            && (bool) ($telegram['url_matches'] ?? false);

        $installation->forceFill([
            'status' => $ok ? TelegramWebhookManager::StatusSynced : TelegramWebhookManager::StatusFailed,
        ])->save();

        return [
            'ok' => $ok,
            'message' => $ok
                ? __('app.telegram_webhook_registered')
                : (string) ($telegram['message'] ?? __('app.telegram_webhook_status_failed')),
        ];
    }

    /** @return array{ok: bool, message: string} */
    public function reconnect(Account $account, FestivalSeries $series): array
    {
        $installation = $this->existingInstallationFor($account, $series);

        if (! $installation) {
            return ['ok' => false, 'message' => __('app.telegram_webhook_missing_configuration')];
        }

        $result = $this->webhooks->register($installation);
        $installation->forceFill(['is_enabled' => (bool) $result['ok']])->save();

        return ['ok' => (bool) $result['ok'], 'message' => (string) $result['message']];
    }

    /** @return array{ok: bool, message: string} */
    public function disable(Account $account, FestivalSeries $series): array
    {
        $installation = $this->existingInstallationFor($account, $series);

        if (! $installation) {
            return ['ok' => false, 'message' => __('app.telegram_webhook_missing_configuration')];
        }

        $result = $this->webhooks->delete($installation);

        DB::transaction(function () use ($installation): void {
            $installation->forceFill(['is_enabled' => false])->save();
            $this->revokeAuthorizations($installation);
        });

        return ['ok' => (bool) $result['ok'], 'message' => (string) $result['message']];
    }

    /** @return array{ok: bool, message: string} */
    public function disconnect(Account $account, FestivalSeries $series): array
    {
        $installation = $this->existingInstallationFor($account, $series);

        if (! $installation) {
            return ['ok' => true, 'message' => __('app.telegram_bot_disconnected')];
        }

        if ($installation->tokenValue()) {
            $this->webhooks->delete($installation);
        }

        DB::transaction(function () use ($installation, $series): void {
            $this->revokeAuthorizations($installation);
            $this->rotateWebhookCredentials($installation, $series);
            $installation->forceFill([
                'bot_id' => null,
                'bot_username' => null,
                'encrypted_token' => null,
                'token_last_four' => null,
                'status' => 'pending',
                'is_enabled' => false,
                'last_webhook_synced_at' => null,
            ])->save();
        });

        return ['ok' => true, 'message' => __('app.telegram_bot_disconnected')];
    }

    private function installationFor(FestivalSeries $series): TelegramBotInstallation
    {
        return TelegramBotInstallation::query()->firstOrNew([
            'scope_type' => 'festival_series',
            'scope_id' => $series->id,
            'profile' => TelegramBotProfile::Festival->value,
        ], ['account_id' => $series->account_id]);
    }

    private function existingInstallationFor(Account $account, FestivalSeries $series): ?TelegramBotInstallation
    {
        $this->assertSeries($account, $series);

        return TelegramBotInstallation::query()
            ->whereBelongsTo($account)
            ->where('scope_type', 'festival_series')
            ->where('scope_id', $series->id)
            ->where('profile', TelegramBotProfile::Festival->value)
            ->first();
    }

    private function rotateWebhookCredentials(TelegramBotInstallation $installation, FestivalSeries $series): void
    {
        $key = TelegramBotInstallation::generateWebhookKey();
        $secret = Str::random(32);
        $installation->forceFill([
            'account_id' => $series->account_id,
            'scope_type' => 'festival_series',
            'scope_id' => $series->id,
            'profile' => TelegramBotProfile::Festival->value,
            'encrypted_webhook_key' => $key,
            'webhook_key_hash' => TelegramBotInstallation::hashWebhookSecret($key),
            'encrypted_webhook_secret' => $secret,
            'webhook_secret_token_hash' => TelegramBotInstallation::hashWebhookSecret($secret),
            'webhook_url' => route('api.v1.telegram.webhooks.handle', $key),
        ]);
    }

    private function revokeAuthorizations(TelegramBotInstallation $installation): void
    {
        $authorizationIds = $installation->chatAuthorizations()
            ->where('profile', TelegramBotProfile::Festival->value)
            ->pluck('id');

        $installation->chatAuthorizations()
            ->where('profile', TelegramBotProfile::Festival->value)
            ->update([
                'status' => TelegramChatAuthorizationStatus::Revoked->value,
                'revoked_at' => now(),
            ]);

        FestivalNotification::query()
            ->whereIn('telegram_chat_authorization_id', $authorizationIds)
            ->where('channel', FestivalNotificationChannel::Telegram->value)
            ->whereIn('status', [FestivalNotificationStatus::Pending->value, FestivalNotificationStatus::Failed->value])
            ->update([
                'status' => FestivalNotificationStatus::Cancelled->value,
                'cancelled_at' => now(),
                'failure_reason' => 'festival_telegram_authorization_revoked',
            ]);
    }

    private function assertSeries(Account $account, FestivalSeries $series): void
    {
        abort_unless((int) $series->account_id === (int) $account->id, 404);
    }

    private function telegramOk(?Response $response): bool
    {
        return $response?->successful() === true && $response->json('ok') === true;
    }

    private function telegramError(?Response $response, string $fallback): string
    {
        $description = $response?->json('description');

        return is_string($description) && $description !== '' ? $description : $fallback;
    }
}

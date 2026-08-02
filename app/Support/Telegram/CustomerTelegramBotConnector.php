<?php

namespace App\Support\Telegram;

use App\Enums\CustomerNotificationStatus;
use App\Enums\TelegramBotMode;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\Account;
use App\Models\CustomerNotification;
use App\Models\TelegramBotInstallation;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CustomerTelegramBotConnector
{
    public function __construct(
        private readonly TelegramClient $telegramClient,
        private readonly TelegramWebhookManager $webhooks,
    ) {}

    /**
     * @return array{ok: bool, message: string}
     */
    public function connect(Account $account, ?string $newToken, ?string $welcomeMessage): array
    {
        $installation = $this->installationFor($account);
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
                $this->rotateWebhookCredentials($installation, $account);
            }

            $installation->fill([
                'account_id' => $account->id,
                'scope_type' => 'account',
                'scope_id' => $account->id,
                'profile' => TelegramBotProfile::Customer->value,
                'bot_id' => $botId,
                'bot_username' => $botUsername,
                'encrypted_token' => $token,
                'token_last_four' => Str::substr($token, -4),
                'status' => 'configured',
                'is_enabled' => false,
            ])->save();

            if ($oldBotId !== '' && ! hash_equals($oldBotId, $botId)) {
                $this->revokeCustomerLinks($installation);
            }

            $result = $this->webhooks->register($installation->fresh());
            $enabled = (bool) $result['ok'];

            DB::transaction(function () use ($account, $installation, $welcomeMessage, $enabled): void {
                $installation->refresh()->forceFill(['is_enabled' => $enabled])->save();
                $account->telegramBotProfiles()->updateOrCreate(
                    ['profile' => TelegramBotProfile::Customer->value],
                    [
                        'mode' => $enabled ? TelegramBotMode::Simple->value : TelegramBotMode::Disabled->value,
                        'is_enabled' => $enabled,
                        'welcome_message' => $welcomeMessage,
                    ],
                );
            });

            return ['ok' => $enabled, 'message' => (string) $result['message']];
        } catch (Throwable $throwable) {
            report(new RuntimeException('Customer Telegram bot connection failed ('.$throwable::class.').'));

            return ['ok' => false, 'message' => __('app.telegram_webhook_registration_failed')];
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function disable(Account $account): array
    {
        $result = $this->deleteWebhook($account);

        return ['ok' => (bool) $result['ok'], 'message' => (string) $result['message']];
    }

    /**
     * @return array{ok: bool, message: string, status?: array<string, mixed>}
     */
    public function registerWebhook(Account $account): array
    {
        $installation = $this->existingInstallationFor($account);

        if (! $installation) {
            return ['ok' => false, 'message' => __('app.telegram_webhook_missing_configuration')];
        }

        $result = $this->webhooks->register($installation);
        $this->setEnabled($account, $installation, (bool) $result['ok']);

        return $result;
    }

    /**
     * @return array{ok: bool, message: string, status?: array<string, mixed>}
     */
    public function deleteWebhook(Account $account): array
    {
        $installation = $this->existingInstallationFor($account);

        if (! $installation) {
            return ['ok' => false, 'message' => __('app.telegram_webhook_missing_configuration')];
        }

        $result = $this->webhooks->delete($installation);
        $this->setEnabled($account, $installation, false);

        return $result;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function disconnect(Account $account): array
    {
        $installation = $this->existingInstallationFor($account);

        if (! $installation) {
            return ['ok' => true, 'message' => __('app.telegram_bot_disconnected')];
        }

        if ($installation->tokenValue()) {
            $this->webhooks->delete($installation);
        }

        DB::transaction(function () use ($account, $installation): void {
            $this->revokeCustomerLinks($installation);
            $this->rotateWebhookCredentials($installation, $account);
            $installation->forceFill([
                'bot_id' => null,
                'bot_username' => null,
                'encrypted_token' => null,
                'token_last_four' => null,
                'status' => 'pending',
                'is_enabled' => false,
                'last_webhook_synced_at' => null,
            ])->save();
            $account->telegramBotProfiles()->updateOrCreate(
                ['profile' => TelegramBotProfile::Customer->value],
                ['mode' => TelegramBotMode::Disabled->value, 'is_enabled' => false],
            );
        });

        return ['ok' => true, 'message' => __('app.telegram_bot_disconnected')];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function check(Account $account): array
    {
        $installation = $this->existingInstallationFor($account);

        if (! $installation || ! $installation->tokenValue()) {
            return ['ok' => false, 'message' => __('app.telegram_webhook_missing_configuration')];
        }

        $status = $this->webhooks->status($installation);
        $telegram = $status['telegram'];
        $ok = (bool) ($telegram['ok'] ?? false)
            && (bool) ($telegram['is_registered'] ?? false)
            && (bool) ($telegram['url_matches'] ?? false);

        if ($installation) {
            $installation->forceFill(['status' => $ok
                ? TelegramWebhookManager::StatusSynced
                : TelegramWebhookManager::StatusFailed])->save();
        }

        return [
            'ok' => $ok,
            'message' => $ok
                ? __('app.telegram_webhook_registered')
                : (string) ($telegram['message'] ?? __('app.telegram_webhook_status_failed')),
        ];
    }

    private function installationFor(Account $account): TelegramBotInstallation
    {
        return $account->telegramBotInstallations()->firstOrNew([
            'scope_type' => 'account',
            'scope_id' => $account->id,
            'profile' => TelegramBotProfile::Customer->value,
        ]);
    }

    private function existingInstallationFor(Account $account): ?TelegramBotInstallation
    {
        return $account->telegramBotInstallations()
            ->where('scope_type', 'account')
            ->where('scope_id', $account->id)
            ->where('profile', TelegramBotProfile::Customer->value)
            ->first();
    }

    private function rotateWebhookCredentials(TelegramBotInstallation $installation, Account $account): void
    {
        $key = TelegramBotInstallation::generateWebhookKey();
        $secret = Str::random(32);
        $installation->forceFill([
            'account_id' => $account->id,
            'scope_type' => 'account',
            'scope_id' => $account->id,
            'profile' => TelegramBotProfile::Customer->value,
            'encrypted_webhook_key' => $key,
            'webhook_key_hash' => TelegramBotInstallation::hashWebhookSecret($key),
            'encrypted_webhook_secret' => $secret,
            'webhook_secret_token_hash' => TelegramBotInstallation::hashWebhookSecret($secret),
            'webhook_url' => route('api.v1.telegram.webhooks.handle', $key),
        ]);
    }

    private function revokeCustomerLinks(TelegramBotInstallation $installation): void
    {
        $authorizationIds = $installation->chatAuthorizations()
            ->where('profile', TelegramBotProfile::Customer->value)
            ->pluck('id');

        $installation->chatAuthorizations()
            ->where('profile', TelegramBotProfile::Customer->value)
            ->update([
                'status' => TelegramChatAuthorizationStatus::Revoked->value,
                'revoked_at' => now(),
            ]);
        $installation->customerSessions()->delete();
        CustomerNotification::query()
            ->whereIn('telegram_chat_authorization_id', $authorizationIds)
            ->whereIn('status', [
                CustomerNotificationStatus::Pending->value,
                CustomerNotificationStatus::Processing->value,
            ])
            ->update([
                'telegram_chat_authorization_id' => null,
                'resolved_channel' => null,
            ]);
    }

    private function setEnabled(Account $account, TelegramBotInstallation $installation, bool $enabled): void
    {
        DB::transaction(function () use ($account, $installation, $enabled): void {
            $installation->forceFill(['is_enabled' => $enabled])->save();
            $profile = $account->telegramBotProfiles()->firstOrNew([
                'profile' => TelegramBotProfile::Customer->value,
            ]);
            $profile->fill([
                'mode' => $enabled ? TelegramBotMode::Simple->value : TelegramBotMode::Disabled->value,
                'is_enabled' => $enabled,
            ])->save();
        });
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

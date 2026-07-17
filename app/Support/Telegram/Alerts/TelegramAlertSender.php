<?php

namespace App\Support\Telegram\Alerts;

use App\Enums\TelegramAlertRecipientKind;
use App\Enums\TelegramAlertStatus;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\TelegramAlert;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramMessage;
use App\Support\Telegram\TelegramClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class TelegramAlertSender
{
    private const MaxAttempts = 3;

    public function __construct(
        private readonly TelegramClient $telegramClient,
    ) {}

    /**
     * @return array{processed: int, sent: int, retried: int, failed: int}
     */
    public function sendPending(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $results = [
            'processed' => 0,
            'sent' => 0,
            'retried' => 0,
            'failed' => 0,
        ];

        $alertIds = TelegramAlert::query()
            ->whereHas('account', fn ($query) => $query->operational())
            ->where('status', TelegramAlertStatus::Pending->value)
            ->where(fn ($query) => $query
                ->whereNull('next_attempt_at')
                ->orWhere('next_attempt_at', '<=', now()))
            ->orderByRaw('COALESCE(next_attempt_at, created_at)')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($alertIds as $alertId) {
            $alert = $this->claim((int) $alertId);

            if (! $alert) {
                continue;
            }

            $results['processed']++;
            $result = $this->send($alert);
            $results[$result]++;
        }

        return $results;
    }

    private function claim(int $alertId): ?TelegramAlert
    {
        return DB::transaction(function () use ($alertId): ?TelegramAlert {
            $alert = TelegramAlert::query()
                ->whereHas('account', fn ($query) => $query->operational())
                ->whereKey($alertId)
                ->lockForUpdate()
                ->first();

            if (
                ! $alert
                || $alert->status !== TelegramAlertStatus::Pending
                || ($alert->next_attempt_at && $alert->next_attempt_at->isFuture())
            ) {
                return null;
            }

            $alert->forceFill([
                'status' => TelegramAlertStatus::Processing->value,
                'attempts' => $alert->attempts + 1,
            ])->save();

            return $alert->refresh();
        });
    }

    private function send(TelegramAlert $alert): string
    {
        $alert->loadMissing(['account', 'trainer']);

        if (! $alert->account) {
            return $this->retryOrFail($alert, 'alert_account_missing', true);
        }

        if ($alert->account->isReadOnlyDemo()) {
            return $this->retryOrFail($alert, 'read_only_demo', true);
        }

        if (! $alert->account->telegramAlertsEnabled()) {
            return $this->retryOrFail($alert, 'telegram_alerts_disabled_for_studio', true);
        }

        $installation = $this->ownerBotInstallation();

        if (! $installation) {
            return $this->retryOrFail($alert, 'owner_bot_not_configured', true);
        }

        if (! $installation->tokenValue()) {
            return $this->retryOrFail($alert, 'owner_bot_token_missing', true);
        }

        $authorization = $this->authorizationFor($alert, $installation);

        if (! $authorization) {
            return $this->retryOrFail($alert, $alert->trainer_id ? 'trainer_telegram_authorization_missing' : 'trainer_not_assigned', true);
        }

        try {
            $response = $this->telegramClient->sendMessage($installation, $authorization->telegram_chat_id, (string) $alert->text);
        } catch (Throwable $exception) {
            return $this->retryOrFail($alert, $exception->getMessage() ?: 'telegram_request_failed');
        }

        if (! $this->responseSucceeded($response)) {
            return $this->retryOrFail($alert, $this->responseError($response));
        }

        $this->markSent($alert, $installation, $authorization, (string) data_get($response?->json(), 'result.message_id'));

        return 'sent';
    }

    private function ownerBotInstallation(): ?TelegramBotInstallation
    {
        return TelegramBotInstallation::query()
            ->where('scope_type', 'platform')
            ->where('profile', TelegramBotProfile::Owner->value)
            ->where('is_enabled', true)
            ->latest('updated_at')
            ->latest('id')
            ->first();
    }

    private function authorizationFor(TelegramAlert $alert, TelegramBotInstallation $installation): ?TelegramChatAuthorization
    {
        if ($alert->recipient_kind !== TelegramAlertRecipientKind::Trainer || ! $alert->trainer_id) {
            return null;
        }

        return TelegramChatAuthorization::query()
            ->where('account_id', $alert->account_id)
            ->where('telegram_bot_installation_id', $installation->id)
            ->where('trainer_id', $alert->trainer_id)
            ->where('profile', TelegramBotProfile::Owner->value)
            ->where('status', TelegramChatAuthorizationStatus::Authorized->value)
            ->latest('authorized_at')
            ->latest('updated_at')
            ->latest('id')
            ->first();
    }

    private function responseSucceeded(?Response $response): bool
    {
        return $response !== null
            && $response->successful()
            && (bool) $response->json('ok', false);
    }

    private function responseError(?Response $response): string
    {
        if (! $response) {
            return 'telegram_response_missing';
        }

        $message = $response->json('description')
            ?? $response->body()
            ?: 'telegram_request_failed';

        return Str::limit((string) $message, 1000);
    }

    private function retryOrFail(TelegramAlert $alert, string $error, bool $permanent = false): string
    {
        $failed = $permanent || $alert->attempts >= self::MaxAttempts;

        $alert->forceFill([
            'status' => $failed ? TelegramAlertStatus::Failed->value : TelegramAlertStatus::Pending->value,
            'next_attempt_at' => $failed ? null : now()->addMinutes($this->backoffMinutes($alert->attempts)),
            'failed_at' => $failed ? now() : null,
            'last_error' => Str::limit($error, 2000),
        ])->save();

        return $failed ? 'failed' : 'retried';
    }

    private function backoffMinutes(int $attempts): int
    {
        return match ($attempts) {
            1 => 1,
            2 => 5,
            default => 15,
        };
    }

    private function markSent(
        TelegramAlert $alert,
        TelegramBotInstallation $installation,
        TelegramChatAuthorization $authorization,
        ?string $telegramMessageId,
    ): void {
        DB::transaction(function () use ($alert, $installation, $authorization, $telegramMessageId): void {
            $message = TelegramMessage::create([
                'account_id' => $alert->account_id,
                'telegram_bot_installation_id' => $installation->id,
                'telegram_chat_authorization_id' => $authorization->id,
                'telegram_update_id' => null,
                'profile' => TelegramBotProfile::Owner->value,
                'telegram_chat_id' => $authorization->telegram_chat_id,
                'telegram_message_id' => $telegramMessageId,
                'telegram_user_id' => $authorization->telegram_user_id,
                'direction' => 'outbound',
                'message_type' => 'alert',
                'text' => $alert->text,
                'payload' => [
                    'telegram_alert_id' => $alert->id,
                    'type' => $alert->type->value,
                    'payload' => $alert->payload,
                ],
                'sent_at' => now(),
            ]);

            $alert->forceFill([
                'telegram_bot_installation_id' => $installation->id,
                'telegram_chat_authorization_id' => $authorization->id,
                'telegram_chat_id' => $authorization->telegram_chat_id,
                'telegram_message_id' => $telegramMessageId,
                'telegram_user_id' => $authorization->telegram_user_id,
                'status' => TelegramAlertStatus::Sent->value,
                'next_attempt_at' => null,
                'sent_at' => $message->sent_at,
                'failed_at' => null,
                'last_error' => null,
            ])->save();
        });
    }
}

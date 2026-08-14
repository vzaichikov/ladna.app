<?php

namespace App\Support\Telegram\Alerts;

use App\Enums\AccountRole;
use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\TelegramAlertRecipientKind;
use App\Enums\TelegramAlertStatus;
use App\Enums\TelegramAlertType;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\AccountMembership;
use App\Models\ScheduledClassCancellation;
use App\Models\TelegramAlert;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramBroadcastTarget;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramMessage;
use App\Support\Telegram\Announcements\TelegramBroadcastTargetVerifier;
use App\Support\Telegram\TelegramClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class TelegramAlertSender
{
    private const MaxAttempts = 3;

    public function __construct(
        private readonly TelegramClient $telegramClient,
        private readonly TelegramBroadcastTargetVerifier $targetVerifier,
    ) {}

    /**
     * @return array{processed: int, sent: int, retried: int, failed: int}
     */
    public function sendPending(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        $alertIds = TelegramAlert::query()
            ->where(fn (Builder $query): Builder => $query
                ->where('recipient_kind', TelegramAlertRecipientKind::FoundersGroup->value)
                ->orWhereHas('account', fn (Builder $query): Builder => $query->operational()))
            ->where('status', TelegramAlertStatus::Pending->value)
            ->where(fn ($query) => $query
                ->whereNull('next_attempt_at')
                ->orWhere('next_attempt_at', '<=', now()))
            ->orderByRaw('COALESCE(next_attempt_at, created_at)')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        return $this->sendAlertIds($alertIds);
    }

    /**
     * @param  iterable<int, int>  $alertIds
     * @return array{processed: int, sent: int, retried: int, failed: int}
     */
    public function sendAlertIds(iterable $alertIds): array
    {
        $results = [
            'processed' => 0,
            'sent' => 0,
            'retried' => 0,
            'failed' => 0,
        ];

        foreach (Collection::make($alertIds)->map(fn (mixed $alertId): int => (int) $alertId)->unique() as $alertId) {
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
                ->where(fn (Builder $query): Builder => $query
                    ->where('recipient_kind', TelegramAlertRecipientKind::FoundersGroup->value)
                    ->orWhereHas('account', fn (Builder $query): Builder => $query->operational()))
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
        $alert->loadMissing([
            'account.trainerNotificationSetting',
            'trainer',
            'classBooking',
            'scheduledClass',
            'broadcastTarget.installation',
        ]);

        if ($alert->recipient_kind === TelegramAlertRecipientKind::FoundersGroup) {
            return $this->sendFoundersAnnouncement($alert);
        }

        if ($alert->recipient_kind === TelegramAlertRecipientKind::StudioOwner
            && $alert->type !== TelegramAlertType::FestivalUpdate) {
            return $this->retryOrFail($alert, 'studio_owner_broadcast_retired', true);
        }

        if (! $alert->account) {
            return $this->retryOrFail($alert, 'alert_account_missing', true);
        }

        if ($alert->account->isReadOnlyDemo()) {
            return $this->retryOrFail($alert, 'read_only_demo', true);
        }

        if ($alert->recipient_kind !== TelegramAlertRecipientKind::StudioOwner
            && ! $alert->account->telegramAlertsEnabled()) {
            return $this->retryOrFail($alert, 'telegram_alerts_disabled_for_studio', true);
        }

        if (
            $alert->type === TelegramAlertType::TrainerAssignment
            && ! $alert->account->trainerAssignmentAlertScenarioEnabled()
        ) {
            return $this->retryOrFail($alert, 'trainer_assignment_alerts_disabled_for_studio', true);
        }

        if (
            $alert->type === TelegramAlertType::TrainerAssignment
            && ! $this->trainerAssignmentIsStillRelevant($alert)
        ) {
            return $this->retryOrFail($alert, 'trainer_assignment_alert_no_longer_relevant', true);
        }

        if (
            $alert->type === TelegramAlertType::TrainerClassCancellation
            && ! $alert->account->trainerClassCancellationAlertScenarioEnabled()
        ) {
            return $this->retryOrFail($alert, 'trainer_class_cancellation_alerts_disabled_for_studio', true);
        }

        if (
            $alert->type === TelegramAlertType::TrainerClassCancellation
            && ! $this->trainerClassCancellationIsStillRelevant($alert)
        ) {
            return $this->retryOrFail($alert, 'trainer_class_cancellation_alert_no_longer_relevant', true);
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
            return $this->retryOrFail($alert, $this->authorizationMissingError($alert), true);
        }

        if ($alert->fresh()->status !== TelegramAlertStatus::Processing) {
            return 'failed';
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

    private function sendFoundersAnnouncement(TelegramAlert $alert): string
    {
        $target = $alert->broadcastTarget;
        $installation = $target?->installation;

        if (
            ! $target
            || ! $installation
            || $target->purpose !== TelegramBroadcastTarget::PurposeLadnaFounders
            || ! $target->is_enabled
            || ! $target->verified_at
            || $alert->telegram_bot_installation_id !== $installation->id
            || $alert->telegram_chat_id !== $target->telegram_chat_id
            || ! $installation->isPlatformScoped()
            || $installation->profile !== TelegramBotProfile::Owner
            || ! $installation->is_enabled
            || ! $installation->tokenValue()
        ) {
            return $this->retryOrFail($alert, 'founders_target_not_available', true);
        }

        try {
            $verified = $this->targetVerifier->verify(
                $installation,
                $target->telegram_chat_id,
                $target->title,
            );
            $expectedTargetHash = (string) data_get($alert->payload, 'target_hash', '');
            $currentTargetHash = $verified->hash($installation->id, $target->purpose);

            if (
                $target->chat_type !== $verified->chatType
                || $expectedTargetHash === ''
                || ! hash_equals($expectedTargetHash, $currentTargetHash)
            ) {
                return $this->retryOrFail($alert, 'founders_target_changed', true);
            }

            $response = $this->telegramClient->sendMessage(
                $installation,
                $target->telegram_chat_id,
                (string) $alert->text,
            );
        } catch (Throwable $exception) {
            return $this->retryOrFail($alert, $exception->getMessage() ?: 'telegram_request_failed');
        }

        if (! $this->responseSucceeded($response)) {
            return $this->retryOrFail($alert, $this->responseError($response));
        }

        $this->markSent(
            $alert,
            $installation,
            null,
            (string) data_get($response?->json(), 'result.message_id'),
        );

        return 'sent';
    }

    private function ownerBotInstallation(): ?TelegramBotInstallation
    {
        return TelegramBotInstallation::query()
            ->where('scope_type', 'platform')
            ->where('scope_id', 0)
            ->where('profile', TelegramBotProfile::Owner->value)
            ->where('is_enabled', true)
            ->latest('updated_at')
            ->latest('id')
            ->first();
    }

    private function authorizationFor(TelegramAlert $alert, TelegramBotInstallation $installation): ?TelegramChatAuthorization
    {
        return match ($alert->recipient_kind) {
            TelegramAlertRecipientKind::Trainer => $this->trainerAuthorization($alert, $installation),
            TelegramAlertRecipientKind::StudioOwner => $this->studioOwnerAuthorization($alert, $installation),
            TelegramAlertRecipientKind::FoundersGroup => null,
        };
    }

    private function studioOwnerAuthorization(TelegramAlert $alert, TelegramBotInstallation $installation): ?TelegramChatAuthorization
    {
        if (! $alert->telegram_chat_authorization_id) {
            return null;
        }

        return TelegramChatAuthorization::query()
            ->whereKey($alert->telegram_chat_authorization_id)
            ->where('account_id', $alert->account_id)
            ->where('telegram_bot_installation_id', $installation->id)
            ->where('profile', TelegramBotProfile::Owner->value)
            ->where('status', TelegramChatAuthorizationStatus::Authorized->value)
            ->whereIn('user_id', AccountMembership::query()
                ->select('user_id')
                ->where('account_id', $alert->account_id)
                ->where('role', AccountRole::Owner->value))
            ->when(filled($alert->telegram_chat_id), fn (Builder $query): Builder => $query->where('telegram_chat_id', $alert->telegram_chat_id))
            ->first();
    }

    private function trainerAuthorization(TelegramAlert $alert, TelegramBotInstallation $installation): ?TelegramChatAuthorization
    {
        if (! $alert->trainer_id) {
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

    private function authorizationMissingError(TelegramAlert $alert): string
    {
        if ($alert->recipient_kind === TelegramAlertRecipientKind::StudioOwner) {
            return 'studio_owner_telegram_authorization_missing';
        }

        return $alert->trainer_id ? 'trainer_telegram_authorization_missing' : 'trainer_not_assigned';
    }

    private function trainerAssignmentIsStillRelevant(TelegramAlert $alert): bool
    {
        if (! $alert->class_booking_id && ! $alert->scheduled_class_id) {
            return true;
        }

        $booking = $alert->classBooking;
        $scheduledClass = $alert->scheduledClass;

        return $booking !== null
            && $scheduledClass !== null
            && ! $booking->isCorrectedRemoved()
            && in_array($booking->status, [
                ClassBookingStatus::Booked,
                ClassBookingStatus::Attended,
            ], true)
            && $scheduledClass->status === ScheduledClassStatus::Scheduled
            && $scheduledClass->trainer_id === $alert->trainer_id;
    }

    private function trainerClassCancellationIsStillRelevant(TelegramAlert $alert): bool
    {
        $scheduledClass = $alert->scheduledClass;

        if (! $scheduledClass || $scheduledClass->trainer_id !== $alert->trainer_id) {
            return false;
        }

        if (($alert->payload['reason'] ?? null) === QueueTrainerClassCancellationTelegramAlert::ReasonAllBookingsCancelled) {
            return $scheduledClass->status === ScheduledClassStatus::Scheduled
                && $scheduledClass->starts_at->isFuture()
                && ! $scheduledClass->classBookings()
                    ->notCorrectedRemoved()
                    ->whereIn('status', [
                        ClassBookingStatus::Booked->value,
                        ClassBookingStatus::Attended->value,
                    ])
                    ->exists();
        }

        $cancellationId = (int) ($alert->payload['scheduled_class_cancellation_id'] ?? 0);

        return $scheduledClass->status === ScheduledClassStatus::Cancelled
            && $cancellationId > 0
            && ScheduledClassCancellation::query()
                ->whereKey($cancellationId)
                ->where('scheduled_class_id', $scheduledClass->id)
                ->whereNull('restored_at')
                ->exists();
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
        ?TelegramChatAuthorization $authorization,
        ?string $telegramMessageId,
    ): void {
        DB::transaction(function () use ($alert, $installation, $authorization, $telegramMessageId): void {
            $telegramChatId = $authorization?->telegram_chat_id ?? (string) $alert->telegram_chat_id;

            $message = TelegramMessage::create([
                'account_id' => $alert->account_id,
                'telegram_bot_installation_id' => $installation->id,
                'telegram_chat_authorization_id' => $authorization?->id,
                'telegram_update_id' => null,
                'profile' => TelegramBotProfile::Owner->value,
                'telegram_chat_id' => $telegramChatId,
                'telegram_message_id' => $telegramMessageId,
                'telegram_user_id' => $authorization?->telegram_user_id,
                'direction' => 'outbound',
                'message_type' => match ($alert->type) {
                    TelegramAlertType::FestivalUpdate => 'festival_update',
                    TelegramAlertType::OwnerAnnouncement => 'owner_announcement',
                    TelegramAlertType::FoundersAnnouncement => 'founders_announcement',
                    default => 'alert',
                },
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
                'telegram_chat_authorization_id' => $authorization?->id,
                'telegram_chat_id' => $telegramChatId,
                'telegram_message_id' => $telegramMessageId,
                'telegram_user_id' => $authorization?->telegram_user_id,
                'status' => TelegramAlertStatus::Sent->value,
                'next_attempt_at' => null,
                'sent_at' => $message->sent_at,
                'failed_at' => null,
                'last_error' => null,
            ])->save();
        });
    }
}

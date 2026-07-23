<?php

namespace App\Support\Telegram\Announcements;

use App\Enums\TelegramAlertRecipientKind;
use App\Enums\TelegramAlertStatus;
use App\Enums\TelegramAlertType;
use App\Models\TelegramAlert;
use App\Models\TelegramBroadcastTarget;

final readonly class QueueFoundersAnnouncement
{
    public function __construct(private PlatformAnnouncementExecutionGuard $executionGuard) {}

    public function execute(
        TelegramBroadcastTarget $target,
        string $message,
        string $sourceRef,
        string $campaignHash,
        string $targetHash,
    ): TelegramAlert {
        $actor = $this->executionGuard->authorize();
        $installation = $target->installation;
        $dedupeKey = 'founders-announcement:'.hash('sha256', implode("\0", [
            $campaignHash,
            (string) $installation->id,
            $target->telegram_chat_id,
        ]));

        return TelegramAlert::query()->firstOrCreate(
            ['dedupe_key' => $dedupeKey],
            [
                'account_id' => null,
                'trainer_id' => null,
                'scheduled_class_id' => null,
                'class_booking_id' => null,
                'telegram_bot_installation_id' => $installation->id,
                'telegram_chat_authorization_id' => null,
                'telegram_broadcast_target_id' => $target->id,
                'type' => TelegramAlertType::FoundersAnnouncement->value,
                'status' => TelegramAlertStatus::Pending->value,
                'recipient_kind' => TelegramAlertRecipientKind::FoundersGroup->value,
                'telegram_chat_id' => $target->telegram_chat_id,
                'telegram_user_id' => null,
                'text' => $message,
                'payload' => [
                    'campaign_hash' => $campaignHash,
                    'target_hash' => $targetHash,
                    'source_ref' => $sourceRef,
                    'execution_origin' => $actor['origin'],
                    'platform_user_id' => $actor['platform_user_id'],
                ],
                'attempts' => 0,
            ],
        );
    }

    public function campaignHash(string $sourceRef, string $message, string $targetHash): string
    {
        return hash('sha256', implode("\0", [
            mb_strtolower($sourceRef),
            $message,
            $targetHash,
        ]));
    }
}

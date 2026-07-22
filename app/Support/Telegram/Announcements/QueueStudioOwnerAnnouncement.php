<?php

namespace App\Support\Telegram\Announcements;

use App\Enums\TelegramAlertRecipientKind;
use App\Enums\TelegramAlertStatus;
use App\Enums\TelegramAlertType;
use App\Models\TelegramAlert;
use App\Models\TelegramBotInstallation;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

final class QueueStudioOwnerAnnouncement
{
    public function __construct(private readonly StudioOwnerAnnouncementExecutionGuard $executionGuard) {}

    /**
     * @param  array{uk: string, en: string}  $messages
     * @return EloquentCollection<int, TelegramAlert>
     */
    public function execute(
        TelegramBotInstallation $installation,
        StudioOwnerAnnouncementAudience $audience,
        array $messages,
        string $sourceRef,
        string $campaignHash,
        string $audienceHash,
    ): EloquentCollection {
        $actor = $this->executionGuard->authorize();

        return DB::transaction(function () use ($installation, $audience, $messages, $sourceRef, $campaignHash, $audienceHash, $actor): EloquentCollection {
            $alerts = new EloquentCollection;

            foreach ($audience->recipients as $recipient) {
                $authorization = $recipient['authorization'];
                $owner = $recipient['owner'];
                $locale = $recipient['locale'];

                $dedupeKey = 'owner-announcement:'.hash('sha256', implode("\0", [
                    $campaignHash,
                    $installation->id,
                    $authorization->telegram_chat_id,
                ]));

                $alerts->push(TelegramAlert::query()->firstOrCreate(
                    ['dedupe_key' => $dedupeKey],
                    [
                        'account_id' => $authorization->account_id,
                        'trainer_id' => null,
                        'scheduled_class_id' => null,
                        'class_booking_id' => null,
                        'telegram_bot_installation_id' => $installation->id,
                        'telegram_chat_authorization_id' => $authorization->id,
                        'type' => TelegramAlertType::OwnerAnnouncement->value,
                        'status' => TelegramAlertStatus::Pending->value,
                        'recipient_kind' => TelegramAlertRecipientKind::StudioOwner->value,
                        'telegram_chat_id' => $authorization->telegram_chat_id,
                        'telegram_user_id' => $authorization->telegram_user_id,
                        'text' => $messages[$locale],
                        'payload' => [
                            'campaign_hash' => $campaignHash,
                            'audience_hash' => $audienceHash,
                            'locale' => $locale,
                            'owner_user_id' => $owner->id,
                            'resolution' => $recipient['resolution'],
                            'source_ref' => $sourceRef,
                            'execution_origin' => $actor['origin'],
                            'platform_user_id' => $actor['platform_user_id'],
                        ],
                        'attempts' => 0,
                    ],
                ));
            }

            return $alerts;
        });
    }

    /**
     * @param  array{uk: string, en: string}  $messages
     */
    public function campaignHash(string $sourceRef, array $messages): string
    {
        return hash('sha256', implode("\0", [
            mb_strtolower($sourceRef),
            $messages['uk'],
            $messages['en'],
        ]));
    }
}

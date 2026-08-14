<?php

namespace App\Support\Telegram\Alerts;

use App\Enums\TelegramAlertRecipientKind;
use App\Enums\TelegramAlertStatus;
use App\Enums\TelegramAlertType;
use App\Models\Account;
use App\Models\TelegramAlert;

class TelegramAlertProducer
{
    public function __construct(
        private readonly TelegramAlertRendererRegistry $renderers,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, int|string|null>  $context
     */
    public function queue(
        TelegramAlertType $type,
        Account $account,
        TelegramAlertRecipientKind $recipientKind,
        array $payload,
        array $context = [],
        ?string $dedupeKey = null,
    ): TelegramAlert {
        $attributes = [
            'account_id' => $account->id,
            'trainer_id' => $context['trainer_id'] ?? null,
            'scheduled_class_id' => $context['scheduled_class_id'] ?? null,
            'class_booking_id' => $context['class_booking_id'] ?? null,
            'telegram_bot_installation_id' => $context['telegram_bot_installation_id'] ?? null,
            'telegram_chat_authorization_id' => $context['telegram_chat_authorization_id'] ?? null,
            'telegram_chat_id' => $context['telegram_chat_id'] ?? null,
            'telegram_user_id' => $context['telegram_user_id'] ?? null,
            'type' => $type->value,
            'status' => TelegramAlertStatus::Pending->value,
            'recipient_kind' => $recipientKind->value,
            'dedupe_key' => $dedupeKey,
            'text' => $this->renderers->render($type, $account, $payload),
            'payload' => $payload,
            'attempts' => 0,
            'next_attempt_at' => null,
            'sent_at' => null,
            'failed_at' => null,
            'last_error' => null,
        ];

        if ($dedupeKey) {
            return TelegramAlert::query()->firstOrCreate(
                ['dedupe_key' => $dedupeKey],
                $attributes,
            );
        }

        return TelegramAlert::query()->create($attributes);
    }
}

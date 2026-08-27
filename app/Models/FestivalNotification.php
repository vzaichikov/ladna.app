<?php

namespace App\Models;

use App\Enums\FestivalNotificationChannel;
use App\Enums\FestivalNotificationStatus;
use App\Enums\FestivalNotificationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'festival_portal_user_id', 'festival_edition_id', 'festival_entry_id', 'festival_ticket_order_id', 'telegram_chat_authorization_id', 'type', 'channel', 'status', 'recipient_email', 'recipient_phone', 'recipient_name', 'subject', 'text', 'dedupe_key', 'payload', 'attempts', 'available_at', 'sent_at', 'failed_at', 'cancelled_at', 'failure_reason'])]
class FestivalNotification extends Model
{
    protected $attributes = ['channel' => 'email', 'status' => 'pending', 'attempts' => 0];

    protected function casts(): array
    {
        return [
            'type' => FestivalNotificationType::class,
            'channel' => FestivalNotificationChannel::class,
            'status' => FestivalNotificationStatus::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(FestivalPortalUser::class, 'festival_portal_user_id');
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(FestivalEdition::class, 'festival_edition_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(FestivalEntry::class, 'festival_entry_id');
    }

    public function ticketOrder(): BelongsTo
    {
        return $this->belongsTo(FestivalTicketOrder::class, 'festival_ticket_order_id');
    }

    public function telegramAuthorization(): BelongsTo
    {
        return $this->belongsTo(TelegramChatAuthorization::class, 'telegram_chat_authorization_id');
    }
}

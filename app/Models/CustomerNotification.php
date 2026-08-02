<?php

namespace App\Models;

use App\Enums\CustomerNotificationChannel;
use App\Enums\CustomerNotificationRecipientKind;
use App\Enums\CustomerNotificationStatus;
use App\Enums\CustomerNotificationType;
use Database\Factories\CustomerNotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'customer_id', 'scheduled_class_id', 'class_booking_id', 'telegram_chat_authorization_id', 'channel', 'resolved_channel', 'type', 'status', 'recipient_kind', 'dedupe_key', 'recipient_name', 'recipient_phone', 'text', 'payload', 'provider_scope', 'provider', 'provider_message_id', 'attempts', 'scheduled_send_at', 'next_attempt_at', 'sent_at', 'failed_at', 'cancelled_at', 'skipped_at', 'fallback_used_at', 'last_error'])]
class CustomerNotification extends Model
{
    /** @use HasFactory<CustomerNotificationFactory> */
    use HasFactory;

    protected $attributes = [
        'channel' => 'sms',
        'status' => 'pending',
        'recipient_kind' => 'customer',
        'attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => CustomerNotificationChannel::class,
            'resolved_channel' => CustomerNotificationChannel::class,
            'type' => CustomerNotificationType::class,
            'status' => CustomerNotificationStatus::class,
            'recipient_kind' => CustomerNotificationRecipientKind::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'scheduled_send_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'skipped_at' => 'datetime',
            'fallback_used_at' => 'datetime',
        ];
    }

    public function scopeTelegramInteraction(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('channel', CustomerNotificationChannel::Automatic->value)
                ->orWhere('resolved_channel', CustomerNotificationChannel::Telegram->value)
                ->orWhereNotNull('telegram_chat_authorization_id')
                ->orWhereNotNull('fallback_used_at');
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scheduledClass(): BelongsTo
    {
        return $this->belongsTo(ScheduledClass::class);
    }

    public function classBooking(): BelongsTo
    {
        return $this->belongsTo(ClassBooking::class);
    }

    public function telegramChatAuthorization(): BelongsTo
    {
        return $this->belongsTo(TelegramChatAuthorization::class);
    }
}

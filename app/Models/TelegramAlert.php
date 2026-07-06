<?php

namespace App\Models;

use App\Enums\TelegramAlertRecipientKind;
use App\Enums\TelegramAlertStatus;
use App\Enums\TelegramAlertType;
use Database\Factories\TelegramAlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'trainer_id', 'scheduled_class_id', 'class_booking_id', 'telegram_bot_installation_id', 'telegram_chat_authorization_id', 'type', 'status', 'recipient_kind', 'dedupe_key', 'telegram_chat_id', 'telegram_message_id', 'telegram_user_id', 'text', 'payload', 'attempts', 'next_attempt_at', 'sent_at', 'failed_at', 'last_error'])]
class TelegramAlert extends Model
{
    /** @use HasFactory<TelegramAlertFactory> */
    use HasFactory;

    protected $attributes = [
        'type' => 'trainer_assignment',
        'status' => 'pending',
        'recipient_kind' => 'trainer',
        'attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TelegramAlertType::class,
            'status' => TelegramAlertStatus::class,
            'recipient_kind' => TelegramAlertRecipientKind::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function scheduledClass(): BelongsTo
    {
        return $this->belongsTo(ScheduledClass::class);
    }

    public function classBooking(): BelongsTo
    {
        return $this->belongsTo(ClassBooking::class);
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(TelegramBotInstallation::class, 'telegram_bot_installation_id');
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(TelegramChatAuthorization::class, 'telegram_chat_authorization_id');
    }
}

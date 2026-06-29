<?php

namespace App\Models;

use App\Enums\TelegramBotProfile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'telegram_bot_installation_id', 'telegram_chat_authorization_id', 'telegram_update_id', 'profile', 'telegram_chat_id', 'telegram_message_id', 'telegram_user_id', 'direction', 'message_type', 'text', 'payload', 'sent_at'])]
class TelegramMessage extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'profile' => TelegramBotProfile::class,
            'payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(TelegramBotInstallation::class, 'telegram_bot_installation_id');
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(TelegramChatAuthorization::class, 'telegram_chat_authorization_id');
    }

    public function telegramUpdate(): BelongsTo
    {
        return $this->belongsTo(TelegramUpdate::class, 'telegram_update_id');
    }
}

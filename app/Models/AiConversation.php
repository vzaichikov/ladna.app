<?php

namespace App\Models;

use App\Enums\TelegramBotProfile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'telegram_chat_authorization_id', 'user_id', 'trainer_id', 'channel', 'profile', 'status', 'title', 'last_message_at'])]
class AiConversation extends Model
{
    use HasFactory;

    public const StatusActive = 'active';

    public const StatusCleared = 'cleared';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'profile' => TelegramBotProfile::class,
            'last_message_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function telegramChatAuthorization(): BelongsTo
    {
        return $this->belongsTo(TelegramChatAuthorization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiConversationMessage::class);
    }

    public function pendingActions(): HasMany
    {
        return $this->hasMany(AiPendingAction::class);
    }
}

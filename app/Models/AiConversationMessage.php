<?php

namespace App\Models;

use App\Enums\AiConversationMessageRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'ai_conversation_id', 'telegram_message_id', 'role', 'content', 'metadata', 'token_count', 'occurred_at'])]
class AiConversationMessage extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => AiConversationMessageRole::class,
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    public function telegramMessage(): BelongsTo
    {
        return $this->belongsTo(TelegramMessage::class);
    }
}

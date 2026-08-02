<?php

namespace App\Models;

use App\Enums\TelegramCustomerSessionState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'telegram_bot_installation_id', 'telegram_chat_authorization_id', 'telegram_chat_id', 'telegram_user_id', 'locale', 'state', 'encrypted_context', 'expires_at', 'last_interaction_at'])]
class TelegramCustomerSession extends Model
{
    protected $attributes = [
        'locale' => 'uk',
        'state' => 'awaiting_contact',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => TelegramCustomerSessionState::class,
            'encrypted_context' => 'encrypted:array',
            'expires_at' => 'datetime',
            'last_interaction_at' => 'datetime',
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
}

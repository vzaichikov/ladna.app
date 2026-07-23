<?php

namespace App\Models;

use Database\Factories\TelegramBroadcastTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['telegram_bot_installation_id', 'purpose', 'telegram_chat_id', 'title', 'chat_type', 'is_enabled', 'verified_at'])]
class TelegramBroadcastTarget extends Model
{
    public const PurposeLadnaFounders = 'ladna_founders';

    /** @use HasFactory<TelegramBroadcastTargetFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(TelegramBotInstallation::class, 'telegram_bot_installation_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(TelegramAlert::class);
    }
}

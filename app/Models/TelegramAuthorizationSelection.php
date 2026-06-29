<?php

namespace App\Models;

use App\Enums\TelegramBotProfile;
use Database\Factories\TelegramAuthorizationSelectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['telegram_bot_installation_id', 'profile', 'telegram_chat_id', 'telegram_user_id', 'telegram_username', 'phone', 'status', 'expires_at'])]
class TelegramAuthorizationSelection extends Model
{
    /** @use HasFactory<TelegramAuthorizationSelectionFactory> */
    use HasFactory;

    public const StatusPending = 'pending';

    public const StatusAuthorized = 'authorized';

    public const StatusExpired = 'expired';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'profile' => TelegramBotProfile::class,
            'expires_at' => 'datetime',
        ];
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(TelegramBotInstallation::class, 'telegram_bot_installation_id');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(TelegramAuthorizationSelectionCandidate::class);
    }
}

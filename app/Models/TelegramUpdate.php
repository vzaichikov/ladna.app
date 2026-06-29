<?php

namespace App\Models;

use App\Enums\TelegramBotProfile;
use App\Enums\TelegramUpdateStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'telegram_bot_installation_id', 'profile', 'update_id', 'payload', 'status', 'error_message', 'received_at', 'processed_at'])]
class TelegramUpdate extends Model
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
            'status' => TelegramUpdateStatus::class,
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
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
}

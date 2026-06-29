<?php

namespace App\Models;

use App\Enums\TelegramBotMode;
use App\Enums\TelegramBotProfile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'profile', 'mode', 'is_enabled', 'welcome_message', 'settings'])]
class TelegramBotProfileSetting extends Model
{
    use HasFactory;

    protected $table = 'telegram_bot_profiles';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'profile' => TelegramBotProfile::class,
            'mode' => TelegramBotMode::class,
            'is_enabled' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}

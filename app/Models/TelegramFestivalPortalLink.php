<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'telegram_chat_authorization_id', 'festival_portal_user_id'])]
class TelegramFestivalPortalLink extends Model
{
    use HasFactory;

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(TelegramChatAuthorization::class, 'telegram_chat_authorization_id');
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(FestivalPortalUser::class, 'festival_portal_user_id');
    }
}

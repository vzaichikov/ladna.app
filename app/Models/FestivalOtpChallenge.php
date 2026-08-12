<?php

namespace App\Models;

use App\Enums\FestivalPortalRole;
use Database\Factories\FestivalOtpChallengeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'role', 'phone', 'code_hash', 'expires_at', 'consumed_at', 'resend_available_at', 'attempts', 'send_count', 'last_sent_at', 'provider_scope', 'provider', 'ip_address', 'user_agent'])]
class FestivalOtpChallenge extends Model
{
    /** @use HasFactory<FestivalOtpChallengeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'role' => FestivalPortalRole::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'resend_available_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}

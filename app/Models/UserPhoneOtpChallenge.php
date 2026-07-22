<?php

namespace App\Models;

use Database\Factories\UserPhoneOtpChallengeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'phone', 'code_hash', 'expires_at', 'consumed_at', 'resend_available_at', 'attempts', 'send_count', 'last_sent_at', 'provider', 'ip_address', 'user_agent'])]
#[Hidden(['code_hash'])]
class UserPhoneOtpChallenge extends Model
{
    /** @use HasFactory<UserPhoneOtpChallengeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'resend_available_at' => 'datetime',
            'attempts' => 'integer',
            'send_count' => 'integer',
            'last_sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

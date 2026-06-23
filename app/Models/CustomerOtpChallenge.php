<?php

namespace App\Models;

use App\Enums\CustomerOtpSenderScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'phone', 'code_hash', 'expires_at', 'consumed_at', 'resend_available_at', 'attempts', 'send_count', 'last_sent_at', 'provider_scope', 'provider', 'ip_address', 'user_agent'])]
class CustomerOtpChallenge extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'resend_available_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'provider_scope' => CustomerOtpSenderScope::class,
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}

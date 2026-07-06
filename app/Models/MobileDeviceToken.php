<?php

namespace App\Models;

use Database\Factories\MobileDeviceTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'mobile_session_id', 'user_id', 'customer_id', 'provider', 'platform', 'token_hash', 'encrypted_token', 'device_name', 'app_version', 'last_seen_at', 'revoked_at'])]
#[Hidden(['encrypted_token', 'token_hash'])]
class MobileDeviceToken extends Model
{
    /** @use HasFactory<MobileDeviceTokenFactory> */
    use HasFactory;

    protected $attributes = [
        'provider' => 'fcm',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'encrypted_token' => 'encrypted',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function mobileSession(): BelongsTo
    {
        return $this->belongsTo(MobileSession::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}

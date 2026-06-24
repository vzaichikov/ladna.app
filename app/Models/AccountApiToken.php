<?php

namespace App\Models;

use Database\Factories\AccountApiTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'name', 'token_hash', 'encrypted_token', 'last_four', 'is_active', 'last_used_at'])]
#[Hidden(['encrypted_token', 'token_hash'])]
class AccountApiToken extends Model
{
    /** @use HasFactory<AccountApiTokenFactory> */
    use HasFactory;

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'encrypted_token' => 'encrypted',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function tokenValue(): string
    {
        return (string) $this->encrypted_token;
    }
}

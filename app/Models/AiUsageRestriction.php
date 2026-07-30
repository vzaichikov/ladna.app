<?php

namespace App\Models;

use Database\Factories\AiUsageRestrictionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'last_account_id',
    'consecutive_out_of_scope_count',
    'cooldown_level',
    'blocked_reason',
    'blocked_until',
    'last_out_of_scope_at',
    'last_blocked_at',
    'last_channel',
    'manually_unblocked_at',
    'manually_unblocked_by_user_id',
])]
class AiUsageRestriction extends Model
{
    /** @use HasFactory<AiUsageRestrictionFactory> */
    use HasFactory;

    protected $attributes = [
        'consecutive_out_of_scope_count' => 0,
        'cooldown_level' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consecutive_out_of_scope_count' => 'integer',
            'cooldown_level' => 'integer',
            'blocked_until' => 'datetime',
            'last_out_of_scope_at' => 'datetime',
            'last_blocked_at' => 'datetime',
            'manually_unblocked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lastAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'last_account_id');
    }

    public function manuallyUnblockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manually_unblocked_by_user_id');
    }
}

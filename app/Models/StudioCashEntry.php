<?php

namespace App\Models;

use Database\Factories\StudioCashEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudioCashEntry extends Model
{
    /** @use HasFactory<StudioCashEntryFactory> */
    use HasFactory;

    public const DirectionIn = 'cash_in';

    public const DirectionOut = 'cash_out';

    protected $fillable = [
        'account_id',
        'location_id',
        'direction',
        'amount_cents',
        'currency',
        'occurred_at',
        'actor_user_id',
        'actor_trainer_id',
        'actor_name',
        'actor_email',
        'actor_role',
        'reason',
    ];

    protected $attributes = [
        'currency' => 'UAH',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}

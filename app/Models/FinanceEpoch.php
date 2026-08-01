<?php

namespace App\Models;

use Database\Factories\FinanceEpochFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['account_id', 'created_by_user_id', 'starts_at', 'is_legacy', 'idempotency_key', 'reason'])]
class FinanceEpoch extends Model
{
    /** @use HasFactory<FinanceEpochFactory> */
    use HasFactory;

    protected $table = 'finance_epochs';

    protected $attributes = [
        'is_legacy' => false,
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'is_legacy' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function cashEntries(): HasMany
    {
        return $this->hasMany(StudioCashEntry::class);
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(CashboxReconciliation::class);
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Finance epochs are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Finance epochs cannot be deleted.'));
    }
}

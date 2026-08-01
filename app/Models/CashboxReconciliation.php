<?php

namespace App\Models;

use Database\Factories\CashboxReconciliationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['account_id', 'finance_epoch_id', 'location_id', 'cutoff_cash_entry_id', 'kind', 'currency', 'expected_before_cents', 'actual_counted_cents', 'variance_cents', 'idempotency_key', 'occurred_at', 'actor_user_id', 'actor_trainer_id', 'actor_name', 'actor_email', 'actor_role', 'reason'])]
class CashboxReconciliation extends Model
{
    /** @use HasFactory<CashboxReconciliationFactory> */
    use HasFactory;

    public const KindEpochStart = 'epoch_start';

    public const KindCount = 'count';

    protected $attributes = [
        'currency' => 'UAH',
        'kind' => self::KindCount,
    ];

    protected function casts(): array
    {
        return [
            'expected_before_cents' => 'integer',
            'actual_counted_cents' => 'integer',
            'variance_cents' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function financeEpoch(): BelongsTo
    {
        return $this->belongsTo(FinanceEpoch::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function cutoffCashEntry(): BelongsTo
    {
        return $this->belongsTo(StudioCashEntry::class, 'cutoff_cash_entry_id');
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Cashbox reconciliations are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Cashbox reconciliations cannot be deleted.'));
    }
}

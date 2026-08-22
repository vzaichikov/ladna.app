<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['account_id', 'festival_payment_attempt_id', 'festival_charge_id', 'amount_cents', 'currency'])]
class FestivalPaymentAttemptCharge extends Model
{
    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Festival payment allocations are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Festival payment allocations are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'festival_payment_attempt_id' => 'integer',
            'festival_charge_id' => 'integer',
            'amount_cents' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(FestivalPaymentAttempt::class, 'festival_payment_attempt_id');
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(FestivalCharge::class, 'festival_charge_id');
    }
}

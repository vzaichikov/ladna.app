<?php

namespace App\Models;

use Database\Factories\PayrollRunLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['account_id', 'payroll_run_id', 'trainer_id', 'amounts', 'model_names', 'entries', 'incomplete'])]
class PayrollRunLine extends Model
{
    /** @use HasFactory<PayrollRunLineFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amounts' => 'array',
            'model_names' => 'array',
            'entries' => 'array',
            'incomplete' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Payroll run lines are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Payroll run lines cannot be deleted.'));
    }
}

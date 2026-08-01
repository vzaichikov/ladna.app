<?php

namespace App\Models;

use App\Enums\PayrollCadence;
use Database\Factories\PayrollRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['account_id', 'finance_epoch_id', 'supersedes_payroll_run_id', 'cadence', 'period_starts_on', 'period_ends_on', 'status', 'totals', 'incomplete', 'idempotency_key', 'closed_by_user_id', 'closed_at', 'voided_by_user_id', 'voided_at', 'void_reason'])]
class PayrollRun extends Model
{
    /** @use HasFactory<PayrollRunFactory> */
    use HasFactory;

    public const StatusClosed = 'closed';

    public const StatusVoided = 'voided';

    protected $attributes = [
        'status' => self::StatusClosed,
        'incomplete' => false,
    ];

    protected function casts(): array
    {
        return [
            'cadence' => PayrollCadence::class,
            'period_starts_on' => 'date',
            'period_ends_on' => 'date',
            'totals' => 'array',
            'incomplete' => 'boolean',
            'closed_at' => 'datetime',
            'voided_at' => 'datetime',
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

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_payroll_run_id');
    }

    public function replacements(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_payroll_run_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollRunLine::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    public function isClosed(): bool
    {
        return $this->status === self::StatusClosed;
    }

    public function isVoided(): bool
    {
        return $this->status === self::StatusVoided;
    }

    protected static function booted(): void
    {
        static::updating(function (self $payrollRun): void {
            $allowedChanges = ['status', 'voided_by_user_id', 'voided_at', 'void_reason', 'updated_at'];
            $changedAttributes = array_keys($payrollRun->getDirty());

            if ($payrollRun->getOriginal('status') !== self::StatusClosed
                || $payrollRun->status !== self::StatusVoided
                || blank($payrollRun->void_reason)
                || $payrollRun->voided_at === null
                || $payrollRun->voided_by_user_id === null
                || array_diff($changedAttributes, $allowedChanges) !== []) {
                throw new LogicException('Closed payroll runs are immutable.');
            }
        });

        static::deleting(fn (): never => throw new LogicException('Payroll runs cannot be deleted.'));
    }
}

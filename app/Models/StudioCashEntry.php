<?php

namespace App\Models;

use Database\Factories\StudioCashEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StudioCashEntry extends Model
{
    /** @use HasFactory<StudioCashEntryFactory> */
    use HasFactory;

    public const DirectionIn = 'cash_in';

    public const DirectionOut = 'cash_out';

    public const PurposeDeposit = 'deposit';

    public const PurposeOwnerWithdrawal = 'owner_withdrawal';

    public const PurposeOperationalExpense = 'operational_expense';

    public const PurposeExpenseReversal = 'expense_reversal';

    public const PurposePaymentRefund = 'payment_refund';

    public const PurposeCustomerPayment = 'customer_payment';

    public const PurposePaymentCorrectionReversal = 'payment_correction_reversal';

    public const PurposePaymentCorrection = 'payment_correction';

    protected $fillable = [
        'account_id',
        'finance_epoch_id',
        'location_id',
        'studio_expense_id',
        'customer_purchase_id',
        'customer_purchase_correction_id',
        'customer_purchase_refund_id',
        'source_key',
        'direction',
        'purpose',
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
        'purpose' => self::PurposeDeposit,
    ];

    /**
     * @return array<int, string>
     */
    public static function purposes(): array
    {
        return [
            self::PurposeDeposit,
            self::PurposeOwnerWithdrawal,
            self::PurposeOperationalExpense,
            self::PurposeExpenseReversal,
            self::PurposePaymentRefund,
            self::PurposeCustomerPayment,
            self::PurposePaymentCorrectionReversal,
            self::PurposePaymentCorrection,
        ];
    }

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

    public function financeEpoch(): BelongsTo
    {
        return $this->belongsTo(FinanceEpoch::class);
    }

    public function customerPurchase(): BelongsTo
    {
        return $this->belongsTo(CustomerPurchase::class);
    }

    public function customerPurchaseCorrection(): BelongsTo
    {
        return $this->belongsTo(CustomerPurchaseCorrection::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(StudioExpense::class, 'studio_expense_id');
    }

    public function customerPurchaseRefund(): BelongsTo
    {
        return $this->belongsTo(CustomerPurchaseRefund::class);
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Studio cash ledger entries are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Studio cash ledger entries cannot be deleted.'));
    }
}

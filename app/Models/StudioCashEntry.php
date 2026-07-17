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

    public const PurposeDeposit = 'deposit';

    public const PurposeOwnerWithdrawal = 'owner_withdrawal';

    public const PurposeOperationalExpense = 'operational_expense';

    public const PurposeExpenseReversal = 'expense_reversal';

    protected $fillable = [
        'account_id',
        'location_id',
        'studio_expense_id',
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

    public function expense(): BelongsTo
    {
        return $this->belongsTo(StudioExpense::class, 'studio_expense_id');
    }
}

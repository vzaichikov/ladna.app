<?php

namespace App\Models;

use Database\Factories\StudioExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['account_id', 'expense_category_id', 'location_id', 'expense_location_id', 'cash_location_id', 'amount_cents', 'currency', 'payment_method', 'idempotency_key', 'occurred_at', 'actor_user_id', 'actor_trainer_id', 'actor_name', 'actor_email', 'actor_role', 'reason', 'voided_at', 'void_reason', 'voided_by_actor_user_id', 'voided_by_actor_trainer_id', 'voided_by_actor_name', 'voided_by_actor_email', 'voided_by_actor_role'])]
class StudioExpense extends Model
{
    /** @use HasFactory<StudioExpenseFactory> */
    use HasFactory;

    public const PaymentMethodCashdesk = 'cashdesk';

    public const PaymentMethodBankCard = 'bank_card';

    public const PaymentMethodBankTransfer = 'bank_transfer';

    public const PaymentMethodOther = 'other';

    public const StatusActive = 'active';

    public const StatusVoided = 'voided';

    protected $attributes = [
        'currency' => 'UAH',
    ];

    /**
     * @return array<int, string>
     */
    public static function paymentMethods(): array
    {
        return [
            self::PaymentMethodCashdesk,
            self::PaymentMethodBankCard,
            self::PaymentMethodBankTransfer,
            self::PaymentMethodOther,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [
            self::StatusActive,
            self::StatusVoided,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function scopeVoided(Builder $query): Builder
    {
        return $query->whereNotNull('voided_at');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function status(): string
    {
        return $this->isVoided() ? self::StatusVoided : self::StatusActive;
    }

    protected static function booted(): void
    {
        static::updating(function (self $expense): void {
            $allowedChanges = [
                'voided_at',
                'void_reason',
                'voided_by_actor_user_id',
                'voided_by_actor_trainer_id',
                'voided_by_actor_name',
                'voided_by_actor_email',
                'voided_by_actor_role',
                'updated_at',
            ];

            if ($expense->getOriginal('voided_at') !== null
                || $expense->voided_at === null
                || blank($expense->void_reason)
                || array_diff(array_keys($expense->getDirty()), $allowedChanges) !== []) {
                throw new LogicException('Studio expenses are immutable except for the audited void transition.');
            }
        });

        static::deleting(fn (): never => throw new LogicException('Studio expenses cannot be deleted.'));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'expense_location_id');
    }

    public function expenseLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'expense_location_id');
    }

    public function cashLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'cash_location_id');
    }

    public function cashEntries(): HasMany
    {
        return $this->hasMany(StudioCashEntry::class);
    }
}

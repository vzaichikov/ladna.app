<?php

namespace App\Models;

use Database\Factories\StudioExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'expense_category_id', 'location_id', 'amount_cents', 'currency', 'payment_method', 'occurred_at', 'actor_user_id', 'actor_trainer_id', 'actor_name', 'actor_email', 'actor_role', 'reason', 'voided_at', 'void_reason', 'voided_by_actor_user_id', 'voided_by_actor_trainer_id', 'voided_by_actor_name', 'voided_by_actor_email', 'voided_by_actor_role'])]
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
        return $this->belongsTo(Location::class);
    }

    public function cashEntries(): HasMany
    {
        return $this->hasMany(StudioCashEntry::class);
    }
}

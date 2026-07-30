<?php

namespace App\Models;

use Database\Factories\CustomerPurchaseRefundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

#[Fillable(['account_id', 'customer_purchase_id', 'location_id', 'cash_location_id', 'method', 'amount_cents', 'currency', 'refunded_at', 'idempotency_key', 'actor_user_id', 'actor_trainer_id', 'actor_name', 'actor_email', 'actor_role', 'reason'])]
class CustomerPurchaseRefund extends Model
{
    /** @use HasFactory<CustomerPurchaseRefundFactory> */
    use HasFactory;

    public const MethodCash = 'cash';

    public const MethodCashless = 'cashless';

    public const StatusRecorded = 'refund_recorded';

    protected $attributes = [
        'currency' => 'UAH',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'refunded_at' => 'datetime',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function methods(): array
    {
        return [
            self::MethodCash,
            self::MethodCashless,
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function customerPurchase(): BelongsTo
    {
        return $this->belongsTo(CustomerPurchase::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function cashLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'cash_location_id');
    }

    public function cashEntry(): HasOne
    {
        return $this->hasOne(StudioCashEntry::class);
    }

    public function fiscalReceipts(): MorphMany
    {
        return $this->morphMany(FiscalReceipt::class, 'payment');
    }

    public function fiscalReceipt(): MorphOne
    {
        return $this->morphOne(FiscalReceipt::class, 'payment')->latestOfMany();
    }

    public function isCash(): bool
    {
        return $this->method === self::MethodCash;
    }

    public function isCashless(): bool
    {
        return $this->method === self::MethodCashless;
    }

    public function effectiveOccurredAt(): ?Carbon
    {
        return $this->refunded_at ?? $this->created_at;
    }
}

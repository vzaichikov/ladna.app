<?php

namespace App\Models;

use Database\Factories\CustomerPurchaseRefundItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['account_id', 'customer_purchase_refund_id', 'customer_purchase_item_id', 'amount_cents'])]
class CustomerPurchaseRefundItem extends Model
{
    /** @use HasFactory<CustomerPurchaseRefundItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function customerPurchaseRefund(): BelongsTo
    {
        return $this->belongsTo(CustomerPurchaseRefund::class);
    }

    public function customerPurchaseItem(): BelongsTo
    {
        return $this->belongsTo(CustomerPurchaseItem::class);
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Customer purchase refund items are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Customer purchase refund items cannot be deleted.'));
    }
}

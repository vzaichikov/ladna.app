<?php

namespace App\Models;

use Database\Factories\CustomerPurchaseItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'customer_purchase_id', 'class_pass_plan_id', 'customer_class_pass_id', 'position', 'plan_name', 'plan_slug', 'schedule_kind', 'subtotal_cents', 'discount_cents', 'amount_cents', 'currency', 'sessions_count', 'validity_days', 'total_validity_days', 'available_from_time', 'available_until_time', 'allows_any_time', 'any_time_addon_price_cents', 'is_trial'])]
class CustomerPurchaseItem extends Model
{
    /** @use HasFactory<CustomerPurchaseItemFactory> */
    use HasFactory;

    protected $attributes = ['discount_cents' => 0, 'allows_any_time' => false, 'is_trial' => false];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer', 'subtotal_cents' => 'integer', 'discount_cents' => 'integer', 'amount_cents' => 'integer', 'sessions_count' => 'integer', 'validity_days' => 'integer', 'total_validity_days' => 'integer', 'allows_any_time' => 'boolean', 'any_time_addon_price_cents' => 'integer', 'is_trial' => 'boolean'];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(CustomerPurchase::class, 'customer_purchase_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function classPassPlan(): BelongsTo
    {
        return $this->belongsTo(ClassPassPlan::class);
    }

    public function customerClassPass(): BelongsTo
    {
        return $this->belongsTo(CustomerClassPass::class);
    }

    public function refundItems(): HasMany
    {
        return $this->hasMany(CustomerPurchaseRefundItem::class);
    }

    public function refundedAmountCents(): int
    {
        return (int) ($this->relationLoaded('refundItems') ? $this->refundItems->sum('amount_cents') : $this->refundItems()->sum('amount_cents'));
    }

    public function remainingRefundableAmountCents(): int
    {
        return $this->purchase?->isPaid() ? max(0, $this->amount_cents - $this->refundedAmountCents()) : 0;
    }
}

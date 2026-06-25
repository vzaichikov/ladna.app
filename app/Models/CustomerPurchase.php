<?php

namespace App\Models;

use App\Enums\CustomerPurchaseStatus;
use Database\Factories\CustomerPurchaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'customer_id', 'class_pass_plan_id', 'customer_class_pass_id', 'provider', 'order_id', 'gateway_invoice_id', 'gateway_payment_id', 'gateway_status', 'status', 'plan_name', 'plan_slug', 'schedule_kind', 'amount_cents', 'currency', 'sessions_count', 'validity_days', 'total_validity_days', 'gateway_checkout_payload', 'last_callback_payload', 'failure_reason', 'started_at', 'paid_at', 'failed_at', 'expires_at'])]
#[Hidden(['gateway_checkout_payload', 'last_callback_payload'])]
class CustomerPurchase extends Model
{
    /** @use HasFactory<CustomerPurchaseFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'payment_started',
        'currency' => 'UAH',
        'total_validity_days' => 180,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CustomerPurchaseStatus::class,
            'gateway_checkout_payload' => 'encrypted:array',
            'last_callback_payload' => 'encrypted:array',
            'started_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function classPassPlan(): BelongsTo
    {
        return $this->belongsTo(ClassPassPlan::class);
    }

    public function customerClassPass(): BelongsTo
    {
        return $this->belongsTo(CustomerClassPass::class);
    }

    public function isPaid(): bool
    {
        return $this->status === CustomerPurchaseStatus::PaymentPaid;
    }
}

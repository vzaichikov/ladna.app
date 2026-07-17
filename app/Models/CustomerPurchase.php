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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

#[Fillable(['account_id', 'customer_id', 'location_id', 'class_pass_plan_id', 'customer_class_pass_id', 'class_booking_id', 'provider', 'payment_source', 'order_id', 'gateway_invoice_id', 'gateway_payment_id', 'gateway_status', 'status', 'plan_name', 'plan_slug', 'schedule_kind', 'amount_cents', 'currency', 'sessions_count', 'validity_days', 'total_validity_days', 'gateway_checkout_payload', 'last_callback_payload', 'failure_reason', 'started_at', 'paid_at', 'failed_at', 'expires_at'])]
#[Hidden(['gateway_checkout_payload', 'last_callback_payload'])]
class CustomerPurchase extends Model
{
    /** @use HasFactory<CustomerPurchaseFactory> */
    use HasFactory;

    public const ProviderStudioCash = 'studio_cash';

    public const SourceOnlineCheckout = 'online_checkout';

    public const SourceManualCashClassPass = 'manual_cash_class_pass';

    public const SourceManualCashBooking = 'manual_cash_booking';

    protected $attributes = [
        'status' => 'payment_started',
        'payment_source' => self::SourceOnlineCheckout,
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

    public function scopeWithinEffectiveDateRange(Builder $query, mixed $startsAt, mixed $endsAt): Builder
    {
        return $query->where(function (Builder $query) use ($startsAt, $endsAt): void {
            $query
                ->where(function (Builder $query) use ($startsAt, $endsAt): void {
                    $query->whereNotNull('paid_at')->whereBetween('paid_at', [$startsAt, $endsAt]);
                })
                ->orWhere(function (Builder $query) use ($startsAt, $endsAt): void {
                    $query
                        ->whereNull('paid_at')
                        ->whereNotNull('started_at')
                        ->whereBetween('started_at', [$startsAt, $endsAt]);
                })
                ->orWhere(function (Builder $query) use ($startsAt, $endsAt): void {
                    $query
                        ->whereNull('paid_at')
                        ->whereNull('started_at')
                        ->whereBetween('created_at', [$startsAt, $endsAt]);
                });
        });
    }

    public function scopeEffectiveNewestFirst(Builder $query): Builder
    {
        return $query
            ->orderByRaw('COALESCE(paid_at, started_at, created_at) DESC')
            ->orderByDesc('id');
    }

    public function effectiveOccurredAt(): ?Carbon
    {
        return $this->paid_at ?? $this->started_at ?? $this->created_at;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function classPassPlan(): BelongsTo
    {
        return $this->belongsTo(ClassPassPlan::class);
    }

    public function customerClassPass(): BelongsTo
    {
        return $this->belongsTo(CustomerClassPass::class);
    }

    public function classBooking(): BelongsTo
    {
        return $this->belongsTo(ClassBooking::class);
    }

    public function fiscalReceipts(): MorphMany
    {
        return $this->morphMany(FiscalReceipt::class, 'payment');
    }

    public function fiscalReceipt(): MorphOne
    {
        return $this->morphOne(FiscalReceipt::class, 'payment')->latestOfMany();
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(CustomerPurchaseCorrection::class);
    }

    public function isPaid(): bool
    {
        return $this->status === CustomerPurchaseStatus::PaymentPaid;
    }

    public function isManualCashClassPassPayment(): bool
    {
        return $this->payment_source === self::SourceManualCashClassPass;
    }

    public function isManualCashBookingPayment(): bool
    {
        return $this->payment_source === self::SourceManualCashBooking;
    }

    public function isManualCashStudioPayment(): bool
    {
        return in_array($this->payment_source, [
            self::SourceManualCashClassPass,
            self::SourceManualCashBooking,
        ], true);
    }

    public function canBeCorrectedAsStudioCash(): bool
    {
        $hasFiscalReceipts = $this->relationLoaded('fiscalReceipts')
            ? $this->fiscalReceipts->isNotEmpty()
            : $this->fiscalReceipts()->exists();

        return $this->isManualCashStudioPayment()
            && $this->isPaid()
            && ! $hasFiscalReceipts;
    }
}

<?php

namespace App\Models;

use App\Enums\EventOrderSource;
use App\Enums\EventOrderStatus;
use App\Enums\PromoCodeDiscountType;
use Database\Factories\EventOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

#[Fillable(['account_id', 'event_id', 'event_promo_code_id', 'source', 'provider', 'order_id', 'status', 'buyer_name', 'buyer_email', 'buyer_phone', 'locale', 'amount_cents', 'currency', 'promo_name', 'promo_code', 'promo_discount_type', 'promo_discount_value', 'subtotal_cents', 'discount_cents', 'promo_email_hash', 'promo_phone_hash', 'access_token_encrypted', 'access_token_hash', 'gateway_invoice_id', 'gateway_payment_id', 'gateway_status', 'gateway_checkout_payload', 'last_callback_payload', 'failure_reason', 'payment_expires_at', 'expires_at', 'paid_at', 'failed_at', 'terms_accepted_at', 'terms_hash', 'issued_by', 'refunded_by', 'refunded_at', 'refund_reason'])]
#[Hidden(['access_token_encrypted', 'access_token_hash', 'gateway_checkout_payload', 'last_callback_payload', 'promo_email_hash', 'promo_phone_hash'])]
class EventOrder extends Model
{
    /** @use HasFactory<EventOrderFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'pending',
        'source' => 'checkout',
        'locale' => 'uk',
        'amount_cents' => 0,
        'currency' => 'UAH',
    ];

    protected function casts(): array
    {
        return [
            'status' => EventOrderStatus::class,
            'source' => EventOrderSource::class,
            'amount_cents' => 'integer',
            'promo_discount_type' => PromoCodeDiscountType::class,
            'promo_discount_value' => 'integer',
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'access_token_encrypted' => 'encrypted',
            'gateway_checkout_payload' => 'encrypted:array',
            'last_callback_payload' => 'encrypted:array',
            'payment_expires_at' => 'datetime',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function scopeReservingPromotionUse(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereIn('status', [
                EventOrderStatus::Paid->value,
                EventOrderStatus::PaidRequiresRefund->value,
                EventOrderStatus::RefundRequired->value,
                EventOrderStatus::Refunded->value,
            ])->orWhere(function (Builder $query): void {
                $query->where('status', EventOrderStatus::Pending->value)
                    ->where('expires_at', '>', now());
            });
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(EventPromoCode::class, 'event_promo_code_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EventOrderItem::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(EventTicket::class);
    }

    public function cashEntries(): HasMany
    {
        return $this->hasMany(EventCashEntry::class);
    }

    public function eventTickets(): HasMany
    {
        return $this->tickets();
    }

    public function refundedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function isManuallyIssued(): bool
    {
        return $this->source === EventOrderSource::Manual;
    }

    public function manualPaymentMethod(): ?string
    {
        if (! $this->isManuallyIssued() || ! str_starts_with((string) $this->provider, 'manual_')) {
            return null;
        }

        return str($this->provider)->after('manual_')->toString();
    }

    public function fiscalReceipts(): MorphMany
    {
        return $this->morphMany(FiscalReceipt::class, 'payment');
    }

    public function fiscalReceipt(): MorphOne
    {
        return $this->morphOne(FiscalReceipt::class, 'payment')->latestOfMany();
    }

    public function emailDeliveries(): HasMany
    {
        return $this->hasMany(EmailDelivery::class);
    }

    public function isPaid(): bool
    {
        return $this->status->hasIssuedTickets();
    }
}

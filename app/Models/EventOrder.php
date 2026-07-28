<?php

namespace App\Models;

use App\Enums\EventOrderStatus;
use Database\Factories\EventOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

#[Fillable(['account_id', 'event_id', 'provider', 'order_id', 'status', 'buyer_name', 'buyer_email', 'buyer_phone', 'locale', 'amount_cents', 'currency', 'access_token_encrypted', 'access_token_hash', 'gateway_invoice_id', 'gateway_payment_id', 'gateway_status', 'gateway_checkout_payload', 'last_callback_payload', 'failure_reason', 'expires_at', 'paid_at', 'failed_at', 'terms_accepted_at', 'terms_hash', 'refunded_by', 'refunded_at', 'refund_reason'])]
#[Hidden(['access_token_encrypted', 'access_token_hash', 'gateway_checkout_payload', 'last_callback_payload'])]
class EventOrder extends Model
{
    /** @use HasFactory<EventOrderFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'pending',
        'locale' => 'uk',
        'amount_cents' => 0,
        'currency' => 'UAH',
    ];

    protected function casts(): array
    {
        return [
            'status' => EventOrderStatus::class,
            'amount_cents' => 'integer',
            'access_token_encrypted' => 'encrypted',
            'gateway_checkout_payload' => 'encrypted:array',
            'last_callback_payload' => 'encrypted:array',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(EventOrderItem::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(EventTicket::class);
    }

    public function eventTickets(): HasMany
    {
        return $this->tickets();
    }

    public function refundedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
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

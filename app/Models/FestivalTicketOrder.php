<?php

namespace App\Models;

use App\Enums\FestivalTicketOrderSource;
use App\Enums\FestivalTicketOrderStatus;
use Database\Factories\FestivalTicketOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

#[Fillable(['account_id', 'festival_edition_id', 'festival_portal_user_id', 'purchaser_festival_portal_user_id', 'source', 'issued_by_user_id', 'issued_at', 'provider', 'order_id', 'status', 'buyer_name', 'buyer_email', 'buyer_phone', 'locale', 'amount_cents', 'currency', 'access_token_encrypted', 'access_token_hash', 'gateway_invoice_id', 'gateway_payment_id', 'gateway_status', 'gateway_checkout_payload', 'last_callback_payload', 'failure_reason', 'payment_expires_at', 'expires_at', 'paid_at', 'failed_at', 'terms_accepted_at', 'terms_hash', 'refunded_by', 'refunded_at', 'refund_reason'])]
#[Hidden(['access_token_encrypted', 'access_token_hash', 'gateway_checkout_payload', 'last_callback_payload'])]
class FestivalTicketOrder extends Model
{
    /** @use HasFactory<FestivalTicketOrderFactory> */
    use HasFactory;

    protected $attributes = ['source' => 'checkout', 'status' => 'pending', 'locale' => 'uk'];

    protected function casts(): array
    {
        return [
            'source' => FestivalTicketOrderSource::class,
            'status' => FestivalTicketOrderStatus::class,
            'amount_cents' => 'integer',
            'access_token_encrypted' => 'encrypted',
            'gateway_checkout_payload' => 'array',
            'last_callback_payload' => 'array',
            'payment_expires_at' => 'datetime',
            'expires_at' => 'datetime',
            'issued_at' => 'datetime',
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

    public function edition(): BelongsTo
    {
        return $this->belongsTo(FestivalEdition::class, 'festival_edition_id');
    }

    public function festivalEdition(): BelongsTo
    {
        return $this->edition();
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(FestivalPortalUser::class, 'festival_portal_user_id');
    }

    public function purchaser(): BelongsTo
    {
        return $this->belongsTo(FestivalPortalUser::class, 'purchaser_festival_portal_user_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FestivalTicketOrderItem::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(FestivalTicket::class);
    }

    public function cashEntries(): HasMany
    {
        return $this->hasMany(FestivalCashEntry::class);
    }

    public function fiscalReceipts(): MorphMany
    {
        return $this->morphMany(FiscalReceipt::class, 'payment');
    }

    public function fiscalReceipt(): MorphOne
    {
        return $this->morphOne(FiscalReceipt::class, 'payment')->latestOfMany();
    }
}

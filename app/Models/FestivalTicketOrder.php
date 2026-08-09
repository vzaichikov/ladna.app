<?php

namespace App\Models;

use App\Enums\FestivalTicketOrderStatus;
use Database\Factories\FestivalTicketOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_edition_id', 'festival_portal_user_id', 'provider', 'order_id', 'status', 'buyer_name', 'buyer_email', 'buyer_phone', 'locale', 'amount_cents', 'currency', 'access_token_encrypted', 'access_token_hash', 'gateway_invoice_id', 'gateway_payment_id', 'gateway_status', 'gateway_checkout_payload', 'last_callback_payload', 'failure_reason', 'expires_at', 'paid_at', 'failed_at', 'terms_accepted_at', 'terms_hash', 'refunded_by', 'refunded_at', 'refund_reason'])]
#[Hidden(['access_token_encrypted', 'access_token_hash', 'gateway_checkout_payload', 'last_callback_payload'])]
class FestivalTicketOrder extends Model
{
    /** @use HasFactory<FestivalTicketOrderFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'pending', 'locale' => 'uk'];

    protected function casts(): array
    {
        return [
            'status' => FestivalTicketOrderStatus::class,
            'amount_cents' => 'integer',
            'access_token_encrypted' => 'encrypted',
            'gateway_checkout_payload' => 'array',
            'last_callback_payload' => 'array',
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

    public function items(): HasMany
    {
        return $this->hasMany(FestivalTicketOrderItem::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(FestivalTicket::class);
    }
}

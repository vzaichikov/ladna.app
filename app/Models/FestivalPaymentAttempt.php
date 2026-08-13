<?php

namespace App\Models;

use App\Enums\FestivalPaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

#[Fillable(['account_id', 'festival_charge_id', 'provider', 'order_id', 'status', 'amount_cents', 'currency', 'gateway_invoice_id', 'gateway_payment_id', 'gateway_status', 'gateway_checkout_payload', 'last_callback_payload', 'failure_reason', 'expires_at', 'paid_at', 'failed_at'])]
#[Hidden(['gateway_checkout_payload', 'last_callback_payload'])]
class FestivalPaymentAttempt extends Model
{
    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['status' => FestivalPaymentStatus::class, 'amount_cents' => 'integer', 'gateway_checkout_payload' => 'array', 'last_callback_payload' => 'array', 'expires_at' => 'datetime', 'paid_at' => 'datetime', 'failed_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(FestivalCharge::class, 'festival_charge_id');
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

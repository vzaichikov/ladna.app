<?php

namespace App\Models;

use App\Enums\FestivalEditionPurchaseStatus;
use Database\Factories\FestivalEditionPurchaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

#[Fillable(['account_id', 'subscription_plan_id', 'festival_tariff_package_id', 'account_subscription_payment_method_id', 'created_by_user_id', 'festival_edition_id', 'provider', 'status', 'order_id', 'gateway_invoice_id', 'gateway_payment_id', 'gateway_status', 'amount_cents', 'currency', 'idempotency_key', 'gateway_checkout_payload', 'last_callback_payload', 'failure_reason', 'started_at', 'paid_at', 'failed_at', 'cancelled_at', 'expired_at', 'reversed_at', 'redeemed_at'])]
#[Hidden(['gateway_checkout_payload', 'last_callback_payload'])]
class FestivalEditionPurchase extends Model
{
    /** @use HasFactory<FestivalEditionPurchaseFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'payment_started', 'currency' => 'UAH'];

    protected function casts(): array
    {
        return [
            'status' => FestivalEditionPurchaseStatus::class,
            'amount_cents' => 'integer',
            'gateway_checkout_payload' => 'encrypted:array',
            'last_callback_payload' => 'encrypted:array',
            'started_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expired_at' => 'datetime',
            'reversed_at' => 'datetime',
            'redeemed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(FestivalTariffPackage::class, 'festival_tariff_package_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(AccountSubscriptionPaymentMethod::class, 'account_subscription_payment_method_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(FestivalEdition::class, 'festival_edition_id');
    }

    public function fiscalReceipts(): MorphMany
    {
        return $this->morphMany(FiscalReceipt::class, 'payment');
    }

    public function fiscalReceipt(): MorphOne
    {
        return $this->morphOne(FiscalReceipt::class, 'payment')->latestOfMany();
    }

    public function checkoutUrl(): ?string
    {
        $url = data_get($this->gateway_checkout_payload, 'response.pageUrl');

        return is_string($url) && $url !== '' ? $url : null;
    }
}

<?php

namespace App\Models;

use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\AccountSubscriptionPaymentType;
use Database\Factories\AccountSubscriptionPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

#[Fillable(['account_id', 'account_subscription_id', 'subscription_plan_id', 'account_signup_request_id', 'provider', 'payment_type', 'order_id', 'gateway_invoice_id', 'gateway_payment_id', 'gateway_subscription_id', 'gateway_status', 'status', 'amount_cents', 'currency', 'period_starts_at', 'period_ends_at', 'gateway_checkout_payload', 'last_callback_payload', 'failure_reason', 'started_at', 'paid_at', 'failed_at', 'expires_at'])]
class AccountSubscriptionPayment extends Model
{
    /** @use HasFactory<AccountSubscriptionPaymentFactory> */
    use HasFactory;

    protected $attributes = [
        'provider' => 'monopay',
        'status' => 'payment_started',
        'currency' => 'UAH',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_type' => AccountSubscriptionPaymentType::class,
            'status' => AccountSubscriptionPaymentStatus::class,
            'gateway_checkout_payload' => 'array',
            'last_callback_payload' => 'array',
            'period_starts_at' => 'datetime',
            'period_ends_at' => 'datetime',
            'started_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(AccountSubscription::class, 'account_subscription_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function signupRequest(): BelongsTo
    {
        return $this->belongsTo(AccountSignupRequest::class, 'account_signup_request_id');
    }

    public function fiscalReceipts(): MorphMany
    {
        return $this->morphMany(FiscalReceipt::class, 'payment');
    }

    public function fiscalReceipt(): MorphOne
    {
        return $this->morphOne(FiscalReceipt::class, 'payment')->latestOfMany();
    }

    public function isPaid(): bool
    {
        return $this->status === AccountSubscriptionPaymentStatus::PaymentPaid;
    }

    public function checkoutUrl(): ?string
    {
        $response = is_array($this->gateway_checkout_payload['response'] ?? null)
            ? $this->gateway_checkout_payload['response']
            : [];
        $pageUrl = $response['pageUrl'] ?? null;

        return is_string($pageUrl) && $pageUrl !== '' ? $pageUrl : null;
    }
}

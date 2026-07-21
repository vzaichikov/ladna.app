<?php

namespace App\Models;

use App\Enums\SubscriptionBillingInterval;
use App\Enums\SubscriptionBillingMode;
use App\Enums\SubscriptionStatus;
use Database\Factories\AccountSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['account_id', 'subscription_plan_id', 'subscription_price_version_id', 'pending_subscription_price_version_id', 'pending_tariff_change_at', 'status', 'billing_mode', 'billing_interval_v2', 'billable_location_count', 'trial_started_at', 'trial_ends_at', 'billing_anchor_at', 'grace_ends_at', 'cancel_at_period_end', 'cancellation_requested_at', 'renewal_attempts', 'next_retry_at', 'started_at', 'ends_at', 'next_payment_at', 'payment_provider', 'provider_subscription_id', 'provider_status', 'auto_renew_enabled', 'cancelled_at'])]
class AccountSubscription extends Model
{
    /** @use HasFactory<AccountSubscriptionFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'trialing',
        'billing_mode' => 'legacy',
        'cancel_at_period_end' => false,
        'renewal_attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'billing_mode' => SubscriptionBillingMode::class,
            'billing_interval_v2' => SubscriptionBillingInterval::class,
            'billable_location_count' => 'integer',
            'pending_tariff_change_at' => 'datetime',
            'trial_started_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'billing_anchor_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'cancellation_requested_at' => 'datetime',
            'renewal_attempts' => 'integer',
            'next_retry_at' => 'datetime',
            'started_at' => 'datetime',
            'ends_at' => 'datetime',
            'next_payment_at' => 'datetime',
            'auto_renew_enabled' => 'boolean',
            'cancelled_at' => 'datetime',
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

    public function priceVersion(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPriceVersion::class, 'subscription_price_version_id');
    }

    public function pendingPriceVersion(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPriceVersion::class, 'pending_subscription_price_version_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AccountSubscriptionPayment::class);
    }

    public function paymentMethod(): HasOne
    {
        return $this->hasOne(AccountSubscriptionPaymentMethod::class);
    }

    public function billingNotifications(): HasMany
    {
        return $this->hasMany(AccountSubscriptionNotification::class);
    }

    public function usesLocationBilling(): bool
    {
        return $this->billing_mode === SubscriptionBillingMode::LocationV2;
    }

    public function isInGracePeriod(): bool
    {
        return $this->status === SubscriptionStatus::PastDue
            && $this->grace_ends_at?->isFuture() === true;
    }

    public function isCurrent(): bool
    {
        return in_array($this->status, [
            SubscriptionStatus::Trialing,
            SubscriptionStatus::Active,
            SubscriptionStatus::PastDue,
        ], true) && (
            $this->ends_at === null
            || $this->ends_at->isFuture()
            || $this->isInGracePeriod()
        );
    }
}

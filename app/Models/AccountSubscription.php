<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Database\Factories\AccountSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'subscription_plan_id', 'status', 'started_at', 'ends_at', 'next_payment_at', 'payment_provider', 'provider_subscription_id', 'provider_status', 'auto_renew_enabled', 'cancelled_at'])]
class AccountSubscription extends Model
{
    /** @use HasFactory<AccountSubscriptionFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'trialing',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
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

    public function payments(): HasMany
    {
        return $this->hasMany(AccountSubscriptionPayment::class);
    }

    public function isCurrent(): bool
    {
        return in_array($this->status, [
            SubscriptionStatus::Trialing,
            SubscriptionStatus::Active,
            SubscriptionStatus::PastDue,
        ], true) && ($this->ends_at === null || $this->ends_at->isFuture());
    }
}

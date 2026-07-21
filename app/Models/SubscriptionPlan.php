<?php

namespace App\Models;

use App\Enums\SubscriptionPlanType;
use Carbon\CarbonInterface;
use Database\Factories\SubscriptionPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'description', 'price_cents', 'currency', 'billing_interval', 'plan_type', 'access_days', 'public_signup_enabled', 'requires_recurring_payment', 'renewal_lead_days', 'is_active', 'sort_order'])]
class SubscriptionPlan extends Model
{
    /** @use HasFactory<SubscriptionPlanFactory> */
    use HasFactory;

    protected $attributes = [
        'currency' => 'UAH',
        'billing_interval' => 'monthly',
        'plan_type' => 'standard',
        'access_days' => 30,
        'public_signup_enabled' => false,
        'requires_recurring_payment' => false,
        'renewal_lead_days' => 2,
        'is_active' => true,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'plan_type' => SubscriptionPlanType::class,
            'public_signup_enabled' => 'boolean',
            'requires_recurring_payment' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePublicSignup(Builder $query): Builder
    {
        return $query
            ->active()
            ->where('public_signup_enabled', true);
    }

    public function scopeDemo(Builder $query): Builder
    {
        return $query->where('plan_type', SubscriptionPlanType::Demo->value);
    }

    public function scopePromo(Builder $query): Builder
    {
        return $query->where('plan_type', SubscriptionPlanType::Promo->value);
    }

    public function scopeStandard(Builder $query): Builder
    {
        return $query->where('plan_type', SubscriptionPlanType::Standard->value);
    }

    public function scopeBillingV2Assignable(Builder $query): Builder
    {
        return $query
            ->active()
            ->standard()
            ->where('requires_recurring_payment', true);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(AccountSubscription::class);
    }

    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(AccountSubscriptionPayment::class);
    }

    public function priceVersions(): HasMany
    {
        return $this->hasMany(SubscriptionPriceVersion::class);
    }

    public function currentPriceVersion(?CarbonInterface $at = null): ?SubscriptionPriceVersion
    {
        return $this->priceVersions()
            ->published()
            ->effectiveAt($at ?? now())
            ->with('tiers')
            ->first();
    }
}

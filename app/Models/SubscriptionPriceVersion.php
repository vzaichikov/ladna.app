<?php

namespace App\Models;

use App\Enums\SubscriptionPriceStatus;
use App\Support\SaasBilling\SubscriptionPriceTierValidator;
use Carbon\CarbonInterface;
use Database\Factories\SubscriptionPriceVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use LogicException;

#[Fillable(['subscription_plan_id', 'version', 'status', 'currency', 'trial_days', 'annual_discount_percent', 'effective_at', 'published_at', 'retired_at'])]
class SubscriptionPriceVersion extends Model
{
    /** @use HasFactory<SubscriptionPriceVersionFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'draft',
        'currency' => 'UAH',
        'trial_days' => 30,
        'annual_discount_percent' => 10,
    ];

    private bool $allowsLifecycleTransition = false;

    protected static function booted(): void
    {
        static::creating(function (self $priceVersion): void {
            if ($priceVersion->status !== SubscriptionPriceStatus::Draft) {
                throw new LogicException('Price versions must be created as drafts.');
            }
        });

        static::updating(function (self $priceVersion): void {
            if ($priceVersion->wasPublished() && ! $priceVersion->allowsLifecycleTransition) {
                throw new LogicException('Published price versions are immutable.');
            }

            if ($priceVersion->isDirty('status') && ! $priceVersion->allowsLifecycleTransition) {
                throw new LogicException('Use the price-version lifecycle methods to change its status.');
            }

            if ($priceVersion->isDirty('status') && in_array($priceVersion->status, [
                SubscriptionPriceStatus::Scheduled,
                SubscriptionPriceStatus::Published,
            ], true)) {
                app(SubscriptionPriceTierValidator::class)->assertValid($priceVersion->tiers()->get());
            }
        });

        static::deleting(function (self $priceVersion): void {
            if ($priceVersion->wasPublished()) {
                throw new LogicException('Published price versions cannot be deleted.');
            }

            if ($priceVersion->subscriptions()->exists() || $priceVersion->payments()->exists()) {
                throw new LogicException('Used price versions cannot be deleted.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionPriceStatus::class,
            'version' => 'integer',
            'trial_days' => 'integer',
            'annual_discount_percent' => 'integer',
            'effective_at' => 'datetime',
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', SubscriptionPriceStatus::Published->value);
    }

    public function scopeEffectiveAt(Builder $query, CarbonInterface $at): Builder
    {
        return $query
            ->whereNotNull('effective_at')
            ->where('effective_at', '<=', $at)
            ->orderByDesc('effective_at')
            ->orderByDesc('version');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(SubscriptionPriceTier::class)
            ->orderBy('starts_at_location');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(AccountSubscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AccountSubscriptionPayment::class);
    }

    public function schedule(CarbonInterface $effectiveAt): self
    {
        if ($this->status !== SubscriptionPriceStatus::Draft) {
            throw new LogicException('Only draft price versions may be scheduled.');
        }

        $this->assertConfigurationIsValid();

        return $this->saveLifecycleTransition([
            'status' => SubscriptionPriceStatus::Scheduled,
            'effective_at' => $effectiveAt,
            'published_at' => now(),
        ]);
    }

    public function publish(?CarbonInterface $effectiveAt = null): self
    {
        if (! in_array($this->status, [SubscriptionPriceStatus::Draft, SubscriptionPriceStatus::Scheduled], true)) {
            throw new LogicException('Only draft or scheduled price versions may be published.');
        }

        $this->assertConfigurationIsValid();

        return $this->saveLifecycleTransition([
            'status' => SubscriptionPriceStatus::Published,
            'effective_at' => $effectiveAt ?? $this->effective_at ?? now(),
            'published_at' => $this->published_at ?? now(),
        ]);
    }

    public function retire(): self
    {
        if ($this->status !== SubscriptionPriceStatus::Published) {
            throw new LogicException('Only published price versions may be retired.');
        }

        return $this->saveLifecycleTransition([
            'status' => SubscriptionPriceStatus::Retired,
            'retired_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function saveLifecycleTransition(array $attributes): self
    {
        $this->allowsLifecycleTransition = true;

        try {
            $this->forceFill($attributes)->save();
        } finally {
            $this->allowsLifecycleTransition = false;
        }

        return $this->refresh();
    }

    private function wasPublished(): bool
    {
        return $this->published_at !== null
            || in_array($this->getRawOriginal('status'), [
                SubscriptionPriceStatus::Published->value,
                SubscriptionPriceStatus::Retired->value,
            ], true);
    }

    private function assertConfigurationIsValid(): void
    {
        if ($this->version < 1) {
            throw new InvalidArgumentException('The price version number must be positive.');
        }

        if (strlen($this->currency) !== 3) {
            throw new InvalidArgumentException('The price-version currency must be a three-letter code.');
        }

        if ($this->trial_days < 0) {
            throw new InvalidArgumentException('Trial duration cannot be negative.');
        }

        if ($this->annual_discount_percent < 0 || $this->annual_discount_percent > 100) {
            throw new InvalidArgumentException('Annual discount must be between zero and one hundred percent.');
        }
    }
}

<?php

namespace App\Models;

use App\Enums\SubscriptionPriceStatus;
use Database\Factories\SubscriptionPriceTierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['subscription_price_version_id', 'starts_at_location', 'ends_at_location', 'unit_price_cents'])]
class SubscriptionPriceTier extends Model
{
    /** @use HasFactory<SubscriptionPriceTierFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $tier): void {
            $tier->assertPriceVersionIsMutable();
        });

        static::deleting(function (self $tier): void {
            $tier->assertPriceVersionIsMutable();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at_location' => 'integer',
            'ends_at_location' => 'integer',
            'unit_price_cents' => 'integer',
        ];
    }

    public function priceVersion(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPriceVersion::class, 'subscription_price_version_id');
    }

    private function assertPriceVersionIsMutable(): void
    {
        $priceVersion = $this->relationLoaded('priceVersion')
            ? $this->priceVersion
            : SubscriptionPriceVersion::query()->find($this->subscription_price_version_id);

        if ($priceVersion && in_array($priceVersion->status, [
            SubscriptionPriceStatus::Scheduled,
            SubscriptionPriceStatus::Published,
            SubscriptionPriceStatus::Retired,
        ], true)) {
            throw new LogicException('Tiers of a published price version are immutable.');
        }
    }
}

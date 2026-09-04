<?php

namespace App\Models;

use App\Enums\PromoCodeDiscountType;
use Database\Factories\StudioPromoCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'name', 'code', 'discount_type', 'discount_value', 'currency', 'starts_at', 'ends_at', 'max_total_uses', 'max_uses_per_identity', 'is_active'])]
class StudioPromoCode extends Model
{
    /** @use HasFactory<StudioPromoCodeFactory> */
    use HasFactory;

    protected $attributes = [
        'currency' => 'UAH',
        'max_uses_per_identity' => 1,
        'is_active' => true,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'discount_type' => PromoCodeDiscountType::class,
            'discount_value' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_total_uses' => 'integer',
            'max_uses_per_identity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActiveAt(Builder $query, mixed $instant = null): Builder
    {
        $instant ??= now();

        return $query
            ->where('is_active', true)
            ->where('starts_at', '<=', $instant)
            ->where('ends_at', '>=', $instant);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function classPassPlans(): BelongsToMany
    {
        return $this->belongsToMany(ClassPassPlan::class)->withTimestamps();
    }

    public function customerPurchases(): HasMany
    {
        return $this->hasMany(CustomerPurchase::class);
    }
}

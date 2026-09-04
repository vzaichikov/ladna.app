<?php

namespace App\Models;

use App\Enums\PromoCodeDiscountType;
use Database\Factories\FestivalPromoCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_edition_id', 'name', 'code', 'discount_type', 'discount_value', 'currency', 'starts_at', 'ends_at', 'total_usage_limit', 'per_identity_usage_limit', 'is_active'])]
class FestivalPromoCode extends Model
{
    /** @use HasFactory<FestivalPromoCodeFactory> */
    use HasFactory;

    protected $attributes = ['per_identity_usage_limit' => 1, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'discount_type' => PromoCodeDiscountType::class,
            'discount_value' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'total_usage_limit' => 'integer',
            'per_identity_usage_limit' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected function code(): Attribute
    {
        return Attribute::make(set: fn (mixed $value): string => mb_strtoupper(trim((string) $value)));
    }

    public function scopeActiveAt(Builder $query, mixed $moment = null): Builder
    {
        $moment ??= now();

        return $query
            ->where('is_active', true)
            ->where('starts_at', '<=', $moment)
            ->where('ends_at', '>=', $moment);
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

    public function admissionTypes(): BelongsToMany
    {
        return $this->belongsToMany(FestivalAdmissionType::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(FestivalTicketOrder::class);
    }

    public function hasUsageHistory(): bool
    {
        return $this->orders()->exists();
    }
}

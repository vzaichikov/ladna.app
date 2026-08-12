<?php

namespace App\Models;

use App\Enums\FestivalTicketOrderStatus;
use Database\Factories\FestivalAdmissionTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_edition_id', 'name', 'description', 'inventory', 'price_cents', 'early_bird_price_cents', 'early_bird_ends_at', 'early_bird_quota', 'sales_starts_at', 'sales_ends_at', 'max_per_order', 'is_active', 'sort_order'])]
class FestivalAdmissionType extends Model
{
    /** @use HasFactory<FestivalAdmissionTypeFactory> */
    use HasFactory;

    protected $attributes = ['max_per_order' => 10, 'is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'inventory' => 'integer',
            'price_cents' => 'integer',
            'early_bird_price_cents' => 'integer',
            'early_bird_ends_at' => 'datetime',
            'early_bird_quota' => 'integer',
            'sales_starts_at' => 'datetime',
            'sales_ends_at' => 'datetime',
            'max_per_order' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeAvailableForSale(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('sales_starts_at')->orWhere('sales_starts_at', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('sales_ends_at')->orWhere('sales_ends_at', '>=', now()));
    }

    public function saleIsOpen(): bool
    {
        return $this->is_active
            && (! $this->sales_starts_at || $this->sales_starts_at->isPast())
            && (! $this->sales_ends_at || $this->sales_ends_at->isFuture());
    }

    public function soldOrHeldQuantity(): int
    {
        return (int) $this->orderItems()
            ->whereHas('order', fn ($query) => $query
                ->whereIn('status', [FestivalTicketOrderStatus::Pending->value, FestivalTicketOrderStatus::Paid->value, FestivalTicketOrderStatus::PaidRequiresRefund->value])
                ->where(fn ($query) => $query->where('status', '!=', FestivalTicketOrderStatus::Pending->value)->orWhere('expires_at', '>', now())))
            ->sum('quantity');
    }

    public function remainingQuantity(): int
    {
        return max(0, $this->inventory - $this->soldOrHeldQuantity());
    }

    /** @return array{price_cents: int, tier: string} */
    public function currentPrice(): array
    {
        $earlySold = (int) $this->orderItems()
            ->where('price_tier', 'early_bird')
            ->whereHas('order', fn ($query) => $query->whereIn('status', [FestivalTicketOrderStatus::Pending->value, FestivalTicketOrderStatus::Paid->value])->where(fn ($query) => $query->where('status', '!=', FestivalTicketOrderStatus::Pending->value)->orWhere('expires_at', '>', now())))
            ->sum('quantity');
        $earlyAvailable = $this->early_bird_price_cents !== null
            && (! $this->early_bird_ends_at || $this->early_bird_ends_at->isFuture())
            && ($this->early_bird_quota === null || $earlySold < $this->early_bird_quota);

        return $earlyAvailable
            ? ['price_cents' => $this->early_bird_price_cents, 'tier' => 'early_bird']
            : ['price_cents' => $this->price_cents, 'tier' => 'regular'];
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(FestivalEdition::class, 'festival_edition_id');
    }

    public function festivalEdition(): BelongsTo
    {
        return $this->edition();
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(FestivalTicketOrderItem::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(FestivalTicket::class);
    }

    public function hasPurchaseHistory(): bool
    {
        return $this->orderItems()->exists();
    }

    public function hasLockedPurchaseHistory(): bool
    {
        return $this->orderItems()
            ->whereHas('order', fn ($query) => $query->whereIn('status', [
                FestivalTicketOrderStatus::Paid->value,
                FestivalTicketOrderStatus::PaidRequiresRefund->value,
                FestivalTicketOrderStatus::Refunded->value,
            ]))
            ->exists();
    }
}

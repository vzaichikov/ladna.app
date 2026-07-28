<?php

namespace App\Models;

use App\Enums\EventOrderStatus;
use Database\Factories\EventTicketTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'event_id', 'name', 'description', 'inventory', 'price_cents', 'early_bird_price_cents', 'early_bird_ends_at', 'early_bird_quota', 'sales_starts_at', 'sales_ends_at', 'max_per_order', 'is_active', 'sort_order'])]
class EventTicketType extends Model
{
    /** @use HasFactory<EventTicketTypeFactory> */
    use HasFactory;

    protected $attributes = [
        'price_cents' => 0,
        'max_per_order' => 10,
        'is_active' => true,
        'sort_order' => 0,
    ];

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

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(EventOrderItem::class);
    }

    public function soldOrHeldQuantity(): int
    {
        return (int) $this->orderItems()
            ->whereHas('order', fn ($query) => $query
                ->whereIn('status', [
                    EventOrderStatus::Pending->value,
                    EventOrderStatus::Paid->value,
                    EventOrderStatus::RefundRequired->value,
                ])
                ->where(fn ($query) => $query
                    ->where('status', '!=', EventOrderStatus::Pending->value)
                    ->orWhere('expires_at', '>', now())))
            ->sum('quantity');
    }

    public function remainingQuantity(): int
    {
        return max(0, $this->inventory - $this->soldOrHeldQuantity());
    }

    public function earlyBirdSoldOrHeldQuantity(): int
    {
        return (int) $this->orderItems()
            ->where('price_tier', 'early_bird')
            ->whereHas('order', fn ($query) => $query
                ->whereIn('status', [
                    EventOrderStatus::Pending->value,
                    EventOrderStatus::Paid->value,
                    EventOrderStatus::RefundRequired->value,
                ])
                ->where(fn ($query) => $query
                    ->where('status', '!=', EventOrderStatus::Pending->value)
                    ->orWhere('expires_at', '>', now())))
            ->sum('quantity');
    }

    public function earlyBirdIsAvailableFor(int $quantity = 1): bool
    {
        return $this->early_bird_price_cents !== null
            && $this->early_bird_ends_at?->isFuture()
            && ($this->early_bird_quota === null || $this->earlyBirdSoldOrHeldQuantity() + $quantity <= $this->early_bird_quota);
    }

    public function currentPriceCents(): int
    {
        return $this->earlyBirdIsAvailableFor()
            ? $this->early_bird_price_cents
            : $this->price_cents;
    }

    public function salesAreOpen(): bool
    {
        return $this->is_active
            && (! $this->sales_starts_at || $this->sales_starts_at->isPast())
            && (! $this->sales_ends_at || $this->sales_ends_at->isFuture());
    }
}

<?php

namespace App\Models;

use App\Enums\EventOrderStatus;
use App\Enums\EventStatus;
use App\Enums\EventVenueKind;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'location_id', 'slug', 'status', 'title', 'summary', 'description_html', 'rules_html', 'venue_kind', 'external_venue_name', 'external_address', 'external_map_url', 'external_directions', 'starts_at', 'ends_at', 'timezone', 'currency', 'capacity', 'published_at', 'cancelled_at', 'archived_at'])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'draft',
        'venue_kind' => 'studio',
        'currency' => 'UAH',
    ];

    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'venue_kind' => EventVenueKind::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
            'published_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', EventStatus::Published->value);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('ends_at', '>=', now());
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class)
            ->withPivot('account_id')
            ->withTimestamps();
    }

    public function media(): HasMany
    {
        return $this->hasMany(EventMedia::class)->orderBy('sort_order')->orderBy('id');
    }

    public function ticketTypes(): HasMany
    {
        return $this->hasMany(EventTicketType::class)->orderBy('sort_order')->orderBy('id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(EventOrder::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(EventOrderItem::class);
    }

    public function eventOrders(): HasMany
    {
        return $this->orders();
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(EventTicket::class);
    }

    public function eventTickets(): HasMany
    {
        return $this->tickets();
    }

    public function isPublished(): bool
    {
        return $this->status === EventStatus::Published;
    }

    public function isCompleted(): bool
    {
        return $this->ends_at?->isPast() ?? false;
    }

    public function soldOrHeldQuantity(): int
    {
        return (int) EventOrderItem::query()
            ->where('event_id', $this->id)
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

    public function remainingCapacity(): ?int
    {
        return $this->capacity === null
            ? null
            : max(0, $this->capacity - $this->soldOrHeldQuantity());
    }

    public function remainingAdmissionInventory(): int
    {
        $ticketTypes = $this->relationLoaded('ticketTypes')
            ? $this->ticketTypes->where('is_active', true)
            : $this->ticketTypes()->where('is_active', true)->get();
        $ticketTypeRemaining = (int) $ticketTypes
            ->sum(fn (EventTicketType $ticketType): int => $ticketType->remainingQuantity());
        $eventRemaining = $this->remainingCapacity();

        return $eventRemaining === null
            ? $ticketTypeRemaining
            : min($ticketTypeRemaining, $eventRemaining);
    }
}

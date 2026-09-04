<?php

namespace App\Models;

use App\Enums\PromoCodeDiscountType;
use Database\Factories\EventPromoCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'event_id', 'name', 'code', 'discount_type', 'discount_value', 'currency', 'starts_at', 'ends_at', 'max_total_uses', 'max_uses_per_identity', 'is_active'])]
class EventPromoCode extends Model
{
    /** @use HasFactory<EventPromoCodeFactory> */
    use HasFactory;

    protected $attributes = [
        'max_uses_per_identity' => 1,
        'is_active' => true,
    ];

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

    protected function code(): Attribute
    {
        return Attribute::make(set: fn (mixed $value): string => mb_strtoupper(trim((string) $value)));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function ticketTypes(): BelongsToMany
    {
        return $this->belongsToMany(EventTicketType::class, 'event_promo_code_event_ticket_type')
            ->withPivot(['account_id', 'event_id'])
            ->withTimestamps();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(EventOrder::class);
    }
}

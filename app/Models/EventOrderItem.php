<?php

namespace App\Models;

use Database\Factories\EventOrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'event_id', 'event_order_id', 'event_ticket_type_id', 'ticket_type_name', 'ticket_type_description', 'price_tier', 'unit_price_cents', 'quantity', 'total_cents', 'subtotal_cents', 'discount_cents', 'final_total_cents'])]
class EventOrderItem extends Model
{
    /** @use HasFactory<EventOrderItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'unit_price_cents' => 'integer',
            'quantity' => 'integer',
            'total_cents' => 'integer',
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'final_total_cents' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(EventOrder::class, 'event_order_id');
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(EventTicketType::class, 'event_ticket_type_id');
    }
}

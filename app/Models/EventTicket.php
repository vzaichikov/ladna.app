<?php

namespace App\Models;

use App\Enums\EventTicketStatus;
use Database\Factories\EventTicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'event_id', 'event_order_id', 'event_order_item_id', 'event_ticket_type_id', 'code', 'token_encrypted', 'token_hash', 'status', 'is_checked_in', 'checked_in_at', 'voided_by', 'voided_at', 'void_reason'])]
#[Hidden(['token_encrypted', 'token_hash'])]
class EventTicket extends Model
{
    /** @use HasFactory<EventTicketFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'valid',
        'is_checked_in' => false,
    ];

    protected function casts(): array
    {
        return [
            'token_encrypted' => 'encrypted',
            'status' => EventTicketStatus::class,
            'is_checked_in' => 'boolean',
            'checked_in_at' => 'datetime',
            'voided_at' => 'datetime',
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

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(EventOrderItem::class, 'event_order_item_id');
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(EventTicketType::class, 'event_ticket_type_id');
    }

    public function checkIns(): HasMany
    {
        return $this->hasMany(EventTicketCheckIn::class);
    }
}

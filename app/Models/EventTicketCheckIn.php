<?php

namespace App\Models;

use Database\Factories\EventTicketCheckInFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'event_id', 'event_ticket_id', 'user_id', 'action', 'source', 'actor_name', 'actor_email', 'reason', 'occurred_at'])]
class EventTicketCheckIn extends Model
{
    /** @use HasFactory<EventTicketCheckInFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(EventTicket::class, 'event_ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

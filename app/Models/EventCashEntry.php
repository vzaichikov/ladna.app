<?php

namespace App\Models;

use Database\Factories\EventCashEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['account_id', 'event_id', 'event_order_id', 'source_key', 'direction', 'purpose', 'amount_cents', 'currency', 'actor_user_id', 'actor_name', 'actor_email', 'actor_role', 'reason', 'occurred_at'])]
class EventCashEntry extends Model
{
    /** @use HasFactory<EventCashEntryFactory> */
    use HasFactory;

    public const DirectionIn = 'cash_in';

    public const DirectionOut = 'cash_out';

    public const PurposeEntranceTicketSale = 'entrance_ticket_sale';

    public const PurposeEntranceTicketRefund = 'entrance_ticket_refund';

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'occurred_at' => 'datetime',
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(EventOrder::class, 'event_order_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Event cash ledger entries are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Event cash ledger entries cannot be deleted.'));
    }
}

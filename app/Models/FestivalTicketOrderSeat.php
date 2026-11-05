<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['festival_ticket_order_item_id', 'festival_seat_id'])]
class FestivalTicketOrderSeat extends Model
{
    public $timestamps = false;

    public function item(): BelongsTo
    {
        return $this->belongsTo(FestivalTicketOrderItem::class, 'festival_ticket_order_item_id');
    }

    public function seat(): BelongsTo
    {
        return $this->belongsTo(FestivalSeat::class, 'festival_seat_id');
    }
}

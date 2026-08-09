<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'festival_edition_id', 'festival_ticket_id', 'actor_user_id', 'action', 'source', 'request_ip', 'reason', 'occurred_at'])]
class FestivalTicketScan extends Model
{
    protected $attributes = ['source' => 'qr'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(FestivalTicket::class, 'festival_ticket_id');
    }
}

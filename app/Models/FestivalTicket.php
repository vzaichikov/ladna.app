<?php

namespace App\Models;

use App\Enums\FestivalTicketStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_edition_id', 'festival_ticket_order_id', 'festival_ticket_order_item_id', 'festival_admission_type_id', 'code', 'token_encrypted', 'token_hash', 'status', 'is_checked_in', 'checked_in_at', 'voided_by', 'voided_at', 'void_reason'])]
#[Hidden(['token_encrypted', 'token_hash'])]
class FestivalTicket extends Model
{
    protected $attributes = ['status' => 'valid', 'is_checked_in' => false];

    protected function casts(): array
    {
        return ['token_encrypted' => 'encrypted', 'status' => FestivalTicketStatus::class, 'is_checked_in' => 'boolean', 'checked_in_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(FestivalEdition::class, 'festival_edition_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(FestivalTicketOrder::class, 'festival_ticket_order_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(FestivalTicketOrderItem::class, 'festival_ticket_order_item_id');
    }

    public function admissionType(): BelongsTo
    {
        return $this->belongsTo(FestivalAdmissionType::class, 'festival_admission_type_id');
    }

    public function scans(): HasMany
    {
        return $this->hasMany(FestivalTicketScan::class);
    }
}

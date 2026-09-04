<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_ticket_order_id', 'festival_admission_type_id', 'admission_name', 'admission_description', 'price_tier', 'unit_price_cents', 'quantity', 'total_cents', 'subtotal_cents', 'discount_cents', 'final_total_cents'])]
class FestivalTicketOrderItem extends Model
{
    protected $attributes = ['price_tier' => 'regular'];

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

    public function order(): BelongsTo
    {
        return $this->belongsTo(FestivalTicketOrder::class, 'festival_ticket_order_id');
    }

    public function admissionType(): BelongsTo
    {
        return $this->belongsTo(FestivalAdmissionType::class, 'festival_admission_type_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(FestivalTicket::class);
    }
}

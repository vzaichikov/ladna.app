<?php

namespace App\Models;

use App\Enums\CustomerClassPassReservationStatus;
use Database\Factories\CustomerClassPassReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'customer_class_pass_id', 'class_booking_id', 'scheduled_class_id', 'status', 'reserved_at', 'used_at', 'released_at'])]
class CustomerClassPassReservation extends Model
{
    /** @use HasFactory<CustomerClassPassReservationFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'reserved',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CustomerClassPassReservationStatus::class,
            'reserved_at' => 'datetime',
            'used_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function customerClassPass(): BelongsTo
    {
        return $this->belongsTo(CustomerClassPass::class);
    }

    public function classBooking(): BelongsTo
    {
        return $this->belongsTo(ClassBooking::class);
    }

    public function scheduledClass(): BelongsTo
    {
        return $this->belongsTo(ScheduledClass::class);
    }
}

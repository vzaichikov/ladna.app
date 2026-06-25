<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'scheduled_class_cancellation_id', 'class_booking_id', 'customer_class_pass_id', 'customer_class_pass_reservation_id', 'previous_booking_status', 'new_booking_status', 'previous_reservation_status', 'new_reservation_status', 'previous_reserved_at', 'new_reserved_at', 'previous_used_at', 'new_used_at', 'previous_released_at', 'new_released_at', 'added_sessions_count', 'added_validity_days', 'previous_sessions_count', 'new_sessions_count', 'previous_validity_days', 'new_validity_days', 'previous_total_validity_days', 'new_total_validity_days', 'reversed_at'])]
class ScheduledClassCancellationEffect extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_reserved_at' => 'datetime',
            'new_reserved_at' => 'datetime',
            'previous_used_at' => 'datetime',
            'new_used_at' => 'datetime',
            'previous_released_at' => 'datetime',
            'new_released_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function scheduledClassCancellation(): BelongsTo
    {
        return $this->belongsTo(ScheduledClassCancellation::class);
    }

    public function classBooking(): BelongsTo
    {
        return $this->belongsTo(ClassBooking::class);
    }

    public function customerClassPass(): BelongsTo
    {
        return $this->belongsTo(CustomerClassPass::class);
    }

    public function customerClassPassReservation(): BelongsTo
    {
        return $this->belongsTo(CustomerClassPassReservation::class);
    }
}

<?php

namespace App\Models;

use App\Enums\ClassBookingStatus;
use Database\Factories\ClassBookingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ClassBooking extends Model
{
    /** @use HasFactory<ClassBookingFactory> */
    use HasFactory;

    protected $fillable = [
        'account_id',
        'scheduled_class_id',
        'customer_id',
        'booked_by_user_id',
        'booked_by_actor_user_id',
        'booked_by_actor_trainer_id',
        'booked_by_actor_name',
        'booked_by_actor_email',
        'booked_by_actor_role',
        'status',
        'attended_at',
        'notes',
    ];

    protected $attributes = [
        'status' => 'booked',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ClassBookingStatus::class,
            'attended_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function scheduledClass(): BelongsTo
    {
        return $this->belongsTo(ScheduledClass::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function bookedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by_user_id');
    }

    public function classPassReservation(): HasOne
    {
        return $this->hasOne(CustomerClassPassReservation::class);
    }
}

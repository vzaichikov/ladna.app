<?php

namespace App\Models;

use Database\Factories\ClassBookingCorrectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassBookingCorrection extends Model
{
    /** @use HasFactory<ClassBookingCorrectionFactory> */
    use HasFactory;

    public const ActionAdded = 'added';

    public const ActionRemoved = 'removed';

    public const PassEffectAutoMatched = 'auto_matched';

    public const PassEffectNoMatchingPass = 'no_matching_pass';

    public const PassEffectReturnSession = 'return_session';

    public const PassEffectKeepConsumed = 'keep_consumed';

    protected $fillable = [
        'account_id',
        'scheduled_class_id',
        'class_booking_id',
        'old_customer_id',
        'new_customer_id',
        'previous_customer_class_pass_id',
        'new_customer_class_pass_id',
        'customer_class_pass_reservation_id',
        'manual_cash_payment_id',
        'action',
        'pass_effect',
        'old_customer_name',
        'new_customer_name',
        'previous_booking_status',
        'new_booking_status',
        'previous_reservation_status',
        'new_reservation_status',
        'previous_reserved_at',
        'new_reserved_at',
        'previous_used_at',
        'new_used_at',
        'previous_released_at',
        'new_released_at',
        'actor_user_id',
        'actor_trainer_id',
        'actor_name',
        'actor_email',
        'actor_role',
        'reason',
    ];

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

    public function classBooking(): BelongsTo
    {
        return $this->belongsTo(ClassBooking::class);
    }

    public function oldCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'old_customer_id');
    }

    public function newCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'new_customer_id');
    }

    public function previousCustomerClassPass(): BelongsTo
    {
        return $this->belongsTo(CustomerClassPass::class, 'previous_customer_class_pass_id');
    }

    public function newCustomerClassPass(): BelongsTo
    {
        return $this->belongsTo(CustomerClassPass::class, 'new_customer_class_pass_id');
    }

    public function customerClassPassReservation(): BelongsTo
    {
        return $this->belongsTo(CustomerClassPassReservation::class);
    }

    public function manualCashPayment(): BelongsTo
    {
        return $this->belongsTo(CustomerPurchase::class, 'manual_cash_payment_id');
    }
}

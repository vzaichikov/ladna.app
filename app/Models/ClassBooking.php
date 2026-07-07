<?php

namespace App\Models;

use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use Database\Factories\ClassBookingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ClassBooking extends Model
{
    /** @use HasFactory<ClassBookingFactory> */
    use HasFactory;

    public const ManualPaymentDueAnyTimeAddon = 'any_time_addon';

    public const ManualPaymentDueRoomRental = 'room_rental';

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
        'skip_class_pass_reservation',
        'corrected_removed_at',
        'corrected_removed_by_user_id',
    ];

    protected $attributes = [
        'status' => 'booked',
        'skip_class_pass_reservation' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ClassBookingStatus::class,
            'attended_at' => 'datetime',
            'skip_class_pass_reservation' => 'boolean',
            'corrected_removed_at' => 'datetime',
        ];
    }

    public function scopeNotCorrectedRemoved(Builder $query): Builder
    {
        return $query->whereNull('corrected_removed_at');
    }

    public function isCorrectedRemoved(): bool
    {
        return $this->corrected_removed_at !== null;
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

    public function manualCashPayment(): HasOne
    {
        return $this->hasOne(CustomerPurchase::class)
            ->where('payment_source', CustomerPurchase::SourceManualCashBooking);
    }

    public function activeClassPassReservation(): ?CustomerClassPassReservation
    {
        if ($this->relationLoaded('classPassReservation')) {
            $reservation = $this->classPassReservation;

            return $reservation && in_array($reservation->status, [
                CustomerClassPassReservationStatus::Reserved,
                CustomerClassPassReservationStatus::Used,
            ], true) ? $reservation : null;
        }

        return $this->classPassReservation()
            ->whereIn('status', [
                CustomerClassPassReservationStatus::Reserved->value,
                CustomerClassPassReservationStatus::Used->value,
            ])
            ->first();
    }

    public function anyTimeAddonAmountCents(): ?int
    {
        $this->loadMissing(['scheduledClass', 'classPassReservation.customerClassPass']);

        if (! $this->scheduledClass) {
            return null;
        }

        $reservation = $this->activeClassPassReservation();
        $reservation?->loadMissing('customerClassPass');

        return $reservation?->customerClassPass?->anyTimeAddonAmountCentsFor($this->scheduledClass);
    }

    public function manualCashPaymentDueKind(?ScheduledClass $scheduledClass = null): ?string
    {
        if ($scheduledClass) {
            $this->setRelation('scheduledClass', $scheduledClass);
        }

        if ($this->isCorrectedRemoved() || ! in_array($this->status, [
            ClassBookingStatus::Booked,
            ClassBookingStatus::Attended,
        ], true)) {
            return null;
        }

        $this->loadMissing(['scheduledClass.classType', 'manualCashPayment', 'classPassReservation.customerClassPass']);

        if ($this->manualCashPayment || ! $this->scheduledClass || $this->scheduledClass->status !== ScheduledClassStatus::Scheduled) {
            return null;
        }

        $activeReservation = $this->activeClassPassReservation();

        if ($this->scheduledClass->classType?->schedule_kind === ScheduleKind::RoomRental && ! $activeReservation) {
            return self::ManualPaymentDueRoomRental;
        }

        if ($activeReservation && ($this->anyTimeAddonAmountCents() ?? 0) > 0) {
            return self::ManualPaymentDueAnyTimeAddon;
        }

        return null;
    }

    public function manualCashPaymentDueAmountCents(?ScheduledClass $scheduledClass = null): ?int
    {
        return $this->manualCashPaymentDueKind($scheduledClass) === self::ManualPaymentDueAnyTimeAddon
            ? $this->anyTimeAddonAmountCents()
            : null;
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(ClassBookingCorrection::class);
    }
}

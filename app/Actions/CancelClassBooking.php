<?php

namespace App\Actions;

use App\Enums\ClassBookingStatus;
use App\Models\ClassBooking;
use App\Models\ScheduledClass;
use App\Support\ClassBookingCancellationWindow;
use App\Support\CustomerNotifications\ClassBookingNotificationCoordinator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CancelClassBooking
{
    public function __construct(
        private readonly ClassBookingCancellationWindow $cancellationWindow,
        private readonly ReconcileCustomerClassPassForBooking $reconcileCustomerClassPassForBooking,
        private readonly ClassBookingNotificationCoordinator $notifications,
    ) {}

    /**
     * @param  array{notes?: ?string}  $attributes
     */
    public function execute(
        ClassBooking $classBooking,
        array $attributes = [],
        string $cutoffErrorKey = 'booking',
        bool $requireBookedUpcoming = false,
    ): ClassBooking {
        [$cancelledBooking, $statusChanged, $wasActive, $becameEmpty, $cancellationEventId] = DB::transaction(function () use ($classBooking, $attributes, $cutoffErrorKey, $requireBookedUpcoming): array {
            $lockedScheduledClass = ScheduledClass::query()
                ->where('account_id', $classBooking->account_id)
                ->whereKey($classBooking->scheduled_class_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedBooking = ClassBooking::query()
                ->where('account_id', $classBooking->account_id)
                ->whereKey($classBooking->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedScheduledClass->loadMissing('classType');
            $lockedBooking->setRelation('scheduledClass', $lockedScheduledClass);

            if ($requireBookedUpcoming && (
                ! in_array($lockedBooking->status, [
                    ClassBookingStatus::Booked,
                    ClassBookingStatus::Cancelled,
                ], true)
                || $lockedBooking->scheduledClass?->starts_at?->lessThanOrEqualTo(now())
            )) {
                throw ValidationException::withMessages([
                    'booking' => __('app.customer_booking_cancel_unavailable'),
                ]);
            }

            if ($this->cancellationWindow->isLockedForBooking($lockedBooking)) {
                throw ValidationException::withMessages([
                    $cutoffErrorKey => __('app.booking_cancellation_cutoff_locked'),
                ]);
            }

            $statusChanged = $lockedBooking->status !== ClassBookingStatus::Cancelled;
            $wasActive = $statusChanged
                && ! $lockedBooking->isCorrectedRemoved()
                && in_array($lockedBooking->status, [
                    ClassBookingStatus::Booked,
                    ClassBookingStatus::Attended,
                ], true);
            $lockedBooking->forceFill([
                ...$attributes,
                'status' => ClassBookingStatus::Cancelled->value,
                'attended_at' => null,
            ])->save();

            $this->reconcileCustomerClassPassForBooking->execute($lockedBooking);
            $becameEmpty = $wasActive && ! $lockedScheduledClass->classBookings()
                ->notCorrectedRemoved()
                ->whereIn('status', [
                    ClassBookingStatus::Booked->value,
                    ClassBookingStatus::Attended->value,
                ])
                ->exists();
            $cancellationEventId = $wasActive ? (string) Str::uuid() : null;

            return [$lockedBooking->refresh(), $statusChanged, $wasActive, $becameEmpty, $cancellationEventId];
        }, attempts: 3);

        if ($statusChanged) {
            $this->notifications->bookingCancelled($cancelledBooking, $wasActive, $becameEmpty, $cancellationEventId);
        }

        return $cancelledBooking;
    }
}

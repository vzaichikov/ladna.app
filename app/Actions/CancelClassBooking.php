<?php

namespace App\Actions;

use App\Enums\ClassBookingStatus;
use App\Models\ClassBooking;
use App\Support\ClassBookingCancellationWindow;
use App\Support\CustomerNotifications\ClassBookingNotificationCoordinator;
use Illuminate\Support\Facades\DB;
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
        [$cancelledBooking, $statusChanged] = DB::transaction(function () use ($classBooking, $attributes, $cutoffErrorKey, $requireBookedUpcoming): array {
            $lockedBooking = ClassBooking::query()
                ->where('account_id', $classBooking->account_id)
                ->whereKey($classBooking->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedBooking->loadMissing('scheduledClass.classType');

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
            $lockedBooking->forceFill([
                ...$attributes,
                'status' => ClassBookingStatus::Cancelled->value,
                'attended_at' => null,
            ])->save();

            $this->reconcileCustomerClassPassForBooking->execute($lockedBooking);

            return [$lockedBooking->refresh(), $statusChanged];
        }, attempts: 3);

        if ($statusChanged) {
            $this->notifications->bookingCancelled($cancelledBooking);
        }

        return $cancelledBooking;
    }
}

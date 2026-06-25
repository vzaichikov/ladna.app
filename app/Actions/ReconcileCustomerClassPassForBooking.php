<?php

namespace App\Actions;

use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Models\ClassBooking;
use App\Models\CustomerClassPassReservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReconcileCustomerClassPassForBooking
{
    public function __construct(
        private readonly ReserveCustomerClassPassForBooking $reserveCustomerClassPassForBooking,
        private readonly NormalizeCustomerClassPasses $normalizeCustomerClassPasses,
    ) {}

    public function execute(ClassBooking $classBooking): ?CustomerClassPassReservation
    {
        return DB::transaction(function () use ($classBooking): ?CustomerClassPassReservation {
            $classBooking->loadMissing('classPassReservation.customerClassPass', 'scheduledClass');
            $reservation = $classBooking->classPassReservation;

            if ($classBooking->status === ClassBookingStatus::Booked) {
                if ($reservation && $reservation->status !== CustomerClassPassReservationStatus::Released) {
                    $reservation->update([
                        'status' => CustomerClassPassReservationStatus::Reserved->value,
                        'reserved_at' => $reservation->reserved_at ?? now(),
                        'used_at' => null,
                        'released_at' => null,
                    ]);

                    $this->normalizeCustomerClassPasses->forPass($reservation->customerClassPass()->lockForUpdate()->firstOrFail());

                    return $reservation->refresh();
                }

                return $this->reserveCustomerClassPassForBooking->execute($classBooking);
            }

            if (in_array($classBooking->status, [
                ClassBookingStatus::Attended,
                ClassBookingStatus::Cancelled,
                ClassBookingStatus::NoShow,
            ], true)) {
                $reservation ??= $this->reserveCustomerClassPassForBooking->execute($classBooking);

                if (! $reservation) {
                    return null;
                }

                $reservation->update([
                    'status' => CustomerClassPassReservationStatus::Used->value,
                    'used_at' => $this->usedAt($classBooking),
                    'released_at' => null,
                ]);

                $this->normalizeCustomerClassPasses->forPass($reservation->customerClassPass()->lockForUpdate()->firstOrFail());

                return $reservation->refresh();
            }

            if ($reservation && $reservation->status !== CustomerClassPassReservationStatus::Released) {
                $reservation->update([
                    'status' => CustomerClassPassReservationStatus::Released->value,
                    'released_at' => now(),
                ]);

                $this->normalizeCustomerClassPasses->forPass($reservation->customerClassPass()->lockForUpdate()->firstOrFail());
            }

            return $reservation?->refresh();
        });
    }

    private function usedAt(ClassBooking $classBooking): Carbon
    {
        if ($classBooking->status === ClassBookingStatus::Attended) {
            return $classBooking->attended_at ?? now();
        }

        return $classBooking->scheduledClass?->starts_at ?? now();
    }
}

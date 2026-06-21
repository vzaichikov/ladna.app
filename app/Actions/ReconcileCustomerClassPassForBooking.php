<?php

namespace App\Actions;

use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Models\ClassBooking;
use App\Models\CustomerClassPassReservation;
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
            $classBooking->loadMissing('classPassReservation.customerClassPass');
            $reservation = $classBooking->classPassReservation;

            if ($classBooking->status === ClassBookingStatus::Attended) {
                $reservation ??= $this->reserveCustomerClassPassForBooking->execute($classBooking);

                if (! $reservation) {
                    return null;
                }

                if ($reservation->status !== CustomerClassPassReservationStatus::Used) {
                    $reservation->update([
                        'status' => CustomerClassPassReservationStatus::Used->value,
                        'used_at' => $classBooking->attended_at ?? now(),
                        'released_at' => null,
                    ]);
                }

                $this->normalizeCustomerClassPasses->forPass($reservation->customerClassPass()->lockForUpdate()->firstOrFail());

                return $reservation->refresh();
            }

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
}

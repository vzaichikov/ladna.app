<?php

namespace App\Actions;

use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Models\ClassBooking;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassReservation;
use Illuminate\Support\Facades\DB;

class ReserveCustomerClassPassForBooking
{
    public function execute(ClassBooking $classBooking): ?CustomerClassPassReservation
    {
        $classBooking->loadMissing(['scheduledClass.classType', 'scheduledClass.trainer', 'scheduledClass.room', 'customer']);

        if ($classBooking->skip_class_pass_reservation) {
            return null;
        }

        if (! in_array($classBooking->status, ClassBookingStatus::cases(), true)) {
            return null;
        }

        $existingReservation = $classBooking->classPassReservation()
            ->whereIn('status', [
                CustomerClassPassReservationStatus::Reserved->value,
                CustomerClassPassReservationStatus::Used->value,
            ])
            ->first();

        if ($existingReservation) {
            return $existingReservation;
        }

        return DB::transaction(function () use ($classBooking): ?CustomerClassPassReservation {
            $releasedReservation = $classBooking->classPassReservation()
                ->where('status', CustomerClassPassReservationStatus::Released->value)
                ->lockForUpdate()
                ->first();
            $customerClassPasses = CustomerClassPass::query()
                ->where('account_id', $classBooking->account_id)
                ->where('customer_id', $classBooking->customer_id)
                ->active()
                ->with(['classPassPlan.classTypes', 'classPassPlan.trainerTypes', 'classPassPlan.rooms'])
                ->orderBy('purchased_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $customerClassPass = $customerClassPasses
                ->first(fn (CustomerClassPass $customerClassPass): bool => $customerClassPass->canReserveFor($classBooking->scheduledClass));

            if (! $customerClassPass) {
                return null;
            }

            $attributes = [
                'account_id' => $classBooking->account_id,
                'customer_class_pass_id' => $customerClassPass->id,
                'class_booking_id' => $classBooking->id,
                'scheduled_class_id' => $classBooking->scheduled_class_id,
                'status' => CustomerClassPassReservationStatus::Reserved->value,
                'reserved_at' => now(),
                'used_at' => null,
                'released_at' => null,
            ];

            if ($releasedReservation) {
                $releasedReservation->update($attributes);
                $reservation = $releasedReservation;
            } else {
                $reservation = $customerClassPass->reservations()->create($attributes);
            }

            $customerClassPass->increment('reserved_sessions_count');

            return $reservation;
        });
    }
}

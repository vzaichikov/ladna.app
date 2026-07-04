<?php

namespace App\Actions;

use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\ScheduledClassStatus;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassBookingCorrection;
use App\Models\ScheduledClass;
use App\Models\User;
use App\Support\ActorSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RemoveClosedClassBookingCorrection
{
    public function __construct(
        private readonly NormalizeCustomerClassPasses $normalizeCustomerClassPasses,
        private readonly ActorSnapshot $actorSnapshot,
    ) {}

    public function execute(
        Account $account,
        ClassBooking $classBooking,
        ?User $user,
        string $passEffect,
        string $reason,
    ): ClassBookingCorrection {
        validator(
            ['pass_effect' => $passEffect],
            ['pass_effect' => ['required', Rule::in([
                ClassBookingCorrection::PassEffectReturnSession,
                ClassBookingCorrection::PassEffectKeepConsumed,
            ])]]
        )->validate();

        return DB::transaction(function () use ($account, $classBooking, $user, $passEffect, $reason): ClassBookingCorrection {
            $booking = ClassBooking::query()
                ->with(['scheduledClass', 'customer', 'manualCashPayment'])
                ->whereBelongsTo($account)
                ->whereKey($classBooking->id)
                ->lockForUpdate()
                ->firstOrFail();

            $scheduledClass = $booking->scheduledClass()->lockForUpdate()->firstOrFail();
            $this->ensureCorrectable($scheduledClass);

            if ($booking->isCorrectedRemoved()) {
                throw ValidationException::withMessages([
                    'reason' => __('app.closed_class_booking_already_corrected_removed'),
                ]);
            }

            $reservation = $booking->classPassReservation()
                ->with('customerClassPass')
                ->lockForUpdate()
                ->first();
            $pass = $reservation?->customerClassPass()->lockForUpdate()->first();
            $previousReservationStatus = $reservation?->status?->value;
            $previousReservedAt = $reservation?->reserved_at;
            $previousUsedAt = $reservation?->used_at;
            $previousReleasedAt = $reservation?->released_at;

            $booking->forceFill([
                'corrected_removed_at' => now(),
                'corrected_removed_by_user_id' => $user?->id,
            ])->save();

            if ($reservation && $pass) {
                if ($passEffect === ClassBookingCorrection::PassEffectReturnSession) {
                    $reservation->forceFill([
                        'status' => CustomerClassPassReservationStatus::Released->value,
                        'used_at' => null,
                        'released_at' => now(),
                    ])->save();
                } else {
                    $reservation->forceFill([
                        'status' => CustomerClassPassReservationStatus::Used->value,
                        'used_at' => $reservation->used_at ?? $scheduledClass->starts_at ?? now(),
                        'released_at' => null,
                    ])->save();
                }

                $this->normalizeCustomerClassPasses->forPass($pass);
                $reservation->refresh();
            }

            return ClassBookingCorrection::query()->create([
                'account_id' => $account->id,
                'scheduled_class_id' => $scheduledClass->id,
                'class_booking_id' => $booking->id,
                'old_customer_id' => $booking->customer_id,
                'previous_customer_class_pass_id' => $reservation?->customer_class_pass_id,
                'customer_class_pass_reservation_id' => $reservation?->id,
                'manual_cash_payment_id' => $booking->manualCashPayment?->id,
                'action' => ClassBookingCorrection::ActionRemoved,
                'pass_effect' => $passEffect,
                'old_customer_name' => $booking->customer?->name,
                'previous_booking_status' => $booking->status?->value,
                'new_booking_status' => $booking->status?->value,
                'previous_reservation_status' => $previousReservationStatus,
                'new_reservation_status' => $reservation?->status?->value,
                'previous_reserved_at' => $previousReservedAt,
                'new_reserved_at' => $reservation?->reserved_at,
                'previous_used_at' => $previousUsedAt,
                'new_used_at' => $reservation?->used_at,
                'previous_released_at' => $previousReleasedAt,
                'new_released_at' => $reservation?->released_at,
                ...$this->actorSnapshot->capture($account, $user),
                'reason' => $reason,
            ]);
        });
    }

    private function ensureCorrectable(ScheduledClass $scheduledClass): void
    {
        if ($scheduledClass->status === ScheduledClassStatus::Cancelled || $scheduledClass->ends_at->greaterThan(now())) {
            throw ValidationException::withMessages([
                'reason' => __('app.closed_class_correction_not_available'),
            ]);
        }
    }
}

<?php

namespace App\Actions;

use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassBookingCorrection;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\ScheduledClass;
use App\Models\User;
use App\Support\ActorSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddClosedClassBookingCorrection
{
    public function __construct(
        private readonly ReconcileCustomerClassPassForBooking $reconcileCustomerClassPassForBooking,
        private readonly ActorSnapshot $actorSnapshot,
    ) {}

    public function execute(
        Account $account,
        ScheduledClass $scheduledClass,
        Customer $customer,
        ?User $user,
        ClassBookingStatus $status,
        ?string $notes,
        string $reason,
    ): ClassBookingCorrection {
        return DB::transaction(function () use ($account, $scheduledClass, $customer, $user, $status, $notes, $reason): ClassBookingCorrection {
            $lockedClass = ScheduledClass::query()
                ->with(['classType', 'classBookings' => fn ($query) => $query->notCorrectedRemoved()])
                ->whereBelongsTo($account)
                ->whereKey($scheduledClass->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($customer->account_id !== $account->id) {
                abort(404);
            }

            $this->ensureCorrectable($lockedClass);
            $this->ensureCapacityForAdditionalCustomer($lockedClass, $customer);

            $existingBooking = ClassBooking::query()
                ->whereBelongsTo($account)
                ->whereBelongsTo($lockedClass, 'scheduledClass')
                ->whereBelongsTo($customer)
                ->lockForUpdate()
                ->first();
            $previousReservation = $existingBooking?->classPassReservation()->lockForUpdate()->first();

            $bookingAttributes = [
                'account_id' => $account->id,
                'scheduled_class_id' => $lockedClass->id,
                'customer_id' => $customer->id,
                'booked_by_user_id' => $user?->id,
                ...$this->actorSnapshot->prefixed($account, $user, 'booked_by_actor'),
                'status' => $status->value,
                'attended_at' => $status === ClassBookingStatus::Attended
                    ? ($lockedClass->starts_at ?? now())
                    : null,
                'notes' => filled($notes) ? trim((string) $notes) : null,
                'skip_class_pass_reservation' => false,
                'corrected_removed_at' => null,
                'corrected_removed_by_user_id' => null,
            ];

            if ($existingBooking) {
                $existingBooking->forceFill($bookingAttributes)->save();
                $booking = $existingBooking->refresh();
            } else {
                $booking = ClassBooking::query()->create($bookingAttributes);
            }

            $reservation = $this->reconcileCustomerClassPassForBooking->execute($booking);
            $booking->loadMissing('manualCashPayment');
            $reservation?->loadMissing('customerClassPass');

            return ClassBookingCorrection::query()->create([
                'account_id' => $account->id,
                'scheduled_class_id' => $lockedClass->id,
                'class_booking_id' => $booking->id,
                'new_customer_id' => $customer->id,
                'previous_customer_class_pass_id' => $previousReservation?->customer_class_pass_id,
                'new_customer_class_pass_id' => $reservation?->customer_class_pass_id,
                'customer_class_pass_reservation_id' => $reservation?->id,
                'manual_cash_payment_id' => $booking->manualCashPayment?->id,
                'action' => ClassBookingCorrection::ActionAdded,
                'pass_effect' => $reservation
                    ? ClassBookingCorrection::PassEffectAutoMatched
                    : ClassBookingCorrection::PassEffectNoMatchingPass,
                'new_customer_name' => $customer->name,
                'previous_booking_status' => $existingBooking?->status?->value,
                'new_booking_status' => $booking->status?->value,
                'previous_reservation_status' => $previousReservation?->status?->value,
                'new_reservation_status' => $reservation?->status?->value,
                'previous_reserved_at' => $previousReservation?->reserved_at,
                'new_reserved_at' => $reservation?->reserved_at,
                'previous_used_at' => $previousReservation?->used_at,
                'new_used_at' => $reservation?->used_at,
                'previous_released_at' => $previousReservation?->released_at,
                'new_released_at' => $reservation?->released_at,
                ...$this->actorSnapshot->capture($account, $user),
                'reason' => $reason,
            ]);
        });
    }

    public function matchingPass(Account $account, ScheduledClass $scheduledClass, Customer $customer): ?CustomerClassPass
    {
        if ($customer->account_id !== $account->id || $scheduledClass->account_id !== $account->id) {
            return null;
        }

        return CustomerClassPass::query()
            ->whereBelongsTo($account)
            ->whereBelongsTo($customer)
            ->active()
            ->with(['classPassPlan.classTypes', 'classPassPlan.trainerTypes', 'classPassPlan.rooms'])
            ->orderBy('purchased_at')
            ->orderBy('id')
            ->get()
            ->first(fn (CustomerClassPass $customerClassPass): bool => $customerClassPass->canReserveFor($scheduledClass));
    }

    private function ensureCorrectable(ScheduledClass $scheduledClass): void
    {
        if (! $scheduledClass->acceptsCustomerBookings()) {
            throw ValidationException::withMessages([
                'customer_id' => __('app.class_does_not_accept_customer_bookings'),
            ]);
        }

        if ($scheduledClass->status === ScheduledClassStatus::Cancelled || $scheduledClass->ends_at->greaterThan(now())) {
            throw ValidationException::withMessages([
                'reason' => __('app.closed_class_correction_not_available'),
            ]);
        }
    }

    private function ensureCapacityForAdditionalCustomer(ScheduledClass $scheduledClass, Customer $customer): void
    {
        $activeStatuses = [
            ClassBookingStatus::Booked->value,
            ClassBookingStatus::Attended->value,
        ];

        $existingVisibleBooking = $scheduledClass->classBookings
            ->where('customer_id', $customer->id)
            ->whereNull('corrected_removed_at')
            ->first();

        if ($existingVisibleBooking) {
            return;
        }

        if ($scheduledClass->classType?->schedule_kind !== ScheduleKind::GroupClass) {
            $hasExistingBooking = $scheduledClass->classBookings
                ->filter(fn (ClassBooking $booking): bool => in_array($booking->status->value, $activeStatuses, true))
                ->isNotEmpty();

            if ($hasExistingBooking) {
                throw ValidationException::withMessages([
                    'customer_id' => __('app.manual_class_already_booked'),
                ]);
            }

            return;
        }

        $activeBookings = $scheduledClass->classBookings
            ->filter(fn (ClassBooking $booking): bool => in_array($booking->status->value, $activeStatuses, true))
            ->count();

        if ($activeBookings >= (int) $scheduledClass->capacity) {
            throw ValidationException::withMessages([
                'customer_id' => __('app.no_available_group_slots'),
            ]);
        }
    }
}

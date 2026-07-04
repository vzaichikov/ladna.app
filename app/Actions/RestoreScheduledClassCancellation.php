<?php

namespace App\Actions;

use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\ScheduledClassStatus;
use App\Models\Account;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassReservation;
use App\Models\ScheduledClass;
use App\Models\ScheduledClassCancellation;
use App\Models\ScheduledClassCancellationEffect;
use App\Models\User;
use App\Support\Mail\TransactionalMailDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RestoreScheduledClassCancellation
{
    public function __construct(
        private readonly NormalizeCustomerClassPasses $normalizeCustomerClassPasses,
        private readonly TransactionalMailDispatcher $mailDispatcher,
    ) {}

    public function execute(Account $account, ScheduledClass $scheduledClass, ?User $user): ScheduledClassCancellation
    {
        $cancellation = DB::transaction(function () use ($account, $scheduledClass, $user): ScheduledClassCancellation {
            $lockedClass = ScheduledClass::query()
                ->whereBelongsTo($account)
                ->whereKey($scheduledClass->id)
                ->lockForUpdate()
                ->firstOrFail();
            $cancellation = ScheduledClassCancellation::query()
                ->whereBelongsTo($account)
                ->whereBelongsTo($lockedClass)
                ->whereNull('restored_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $cancellation) {
                throw ValidationException::withMessages([
                    'scheduled_class' => __('app.scheduled_class_restore_unavailable'),
                ]);
            }

            if ($cancellation->isClosedCorrection()) {
                throw ValidationException::withMessages([
                    'scheduled_class' => __('app.scheduled_class_restore_closed_correction_unavailable'),
                ]);
            }

            $effects = $cancellation->effects()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lockedPasses = [];

            foreach ($effects as $effect) {
                $this->assertEffectCanBeRestored($effect, $lockedPasses);
            }

            foreach ($effects as $effect) {
                $this->restoreEffect($effect, $lockedPasses);
            }

            $lockedClass->forceFill([
                'status' => $cancellation->previous_scheduled_class_status ?: ScheduledClassStatus::Scheduled->value,
                'is_manually_modified' => true,
            ])->save();
            $cancellation->forceFill([
                'restored_by_user_id' => $user?->id,
                'restored_at' => now(),
            ])->save();

            return $cancellation->refresh()->load('effects');
        });

        $this->mailDispatcher->scheduledClassRestored($cancellation);

        return $cancellation;
    }

    /**
     * @param  array<int, CustomerClassPass>  $lockedPasses
     */
    private function assertEffectCanBeRestored(ScheduledClassCancellationEffect $effect, array &$lockedPasses): void
    {
        if (! $effect->customer_class_pass_id) {
            return;
        }

        $customerClassPass = $this->lockedPass($effect, $lockedPasses);

        if ($effect->added_sessions_count > 0) {
            $targetSessionsCount = (int) $customerClassPass->sessions_count - (int) $effect->added_sessions_count;

            if ($targetSessionsCount < 0 || $this->sessionsRequiredAfterRestore($customerClassPass, $effect) > $targetSessionsCount) {
                throw ValidationException::withMessages([
                    'scheduled_class' => __('app.scheduled_class_restore_compensation_used'),
                ]);
            }
        }

        if ($effect->added_validity_days > 0) {
            $targetValidityDays = (int) $customerClassPass->validity_days - (int) $effect->added_validity_days;
            $targetTotalValidityDays = (int) $customerClassPass->total_validity_days - (int) $effect->added_validity_days;

            if ($targetValidityDays < 0 || $targetTotalValidityDays < 0) {
                throw ValidationException::withMessages([
                    'scheduled_class' => __('app.scheduled_class_restore_compensation_changed'),
                ]);
            }
        }
    }

    /**
     * @param  array<int, CustomerClassPass>  $lockedPasses
     */
    private function restoreEffect(ScheduledClassCancellationEffect $effect, array &$lockedPasses): void
    {
        $effect->classBooking()->lockForUpdate()->firstOrFail()->forceFill([
            'status' => $effect->previous_booking_status,
            'attended_at' => null,
        ])->save();

        if ($effect->customer_class_pass_id) {
            $customerClassPass = $this->lockedPass($effect, $lockedPasses);
            $passUpdates = [];

            if ($effect->added_sessions_count > 0) {
                $passUpdates['sessions_count'] = (int) $customerClassPass->sessions_count - (int) $effect->added_sessions_count;
            }

            if ($effect->added_validity_days > 0) {
                $passUpdates += [
                    'validity_days' => (int) $customerClassPass->validity_days - (int) $effect->added_validity_days,
                    'total_validity_days' => (int) $customerClassPass->total_validity_days - (int) $effect->added_validity_days,
                ];
            }

            if ($passUpdates !== []) {
                $customerClassPass->forceFill($passUpdates)->save();
            }

            $reservation = $effect->customer_class_pass_reservation_id
                ? CustomerClassPassReservation::query()->whereKey($effect->customer_class_pass_reservation_id)->lockForUpdate()->firstOrFail()
                : null;

            if ($reservation && $effect->previous_reservation_status) {
                $reservation->forceFill([
                    'status' => $effect->previous_reservation_status,
                    'reserved_at' => $effect->previous_reserved_at,
                    'used_at' => $effect->previous_used_at,
                    'released_at' => $effect->previous_released_at,
                ])->save();
            }

            $this->normalizeCustomerClassPasses->forPass($customerClassPass->refresh());
        }

        $effect->forceFill(['reversed_at' => now()])->save();
    }

    /**
     * @param  array<int, CustomerClassPass>  $lockedPasses
     */
    private function lockedPass(ScheduledClassCancellationEffect $effect, array &$lockedPasses): CustomerClassPass
    {
        if (isset($lockedPasses[$effect->customer_class_pass_id])) {
            return $lockedPasses[$effect->customer_class_pass_id];
        }

        $customerClassPass = CustomerClassPass::query()
            ->whereKey($effect->customer_class_pass_id)
            ->lockForUpdate()
            ->first();

        if (! $customerClassPass) {
            throw ValidationException::withMessages([
                'scheduled_class' => __('app.scheduled_class_restore_pass_missing'),
            ]);
        }

        return $lockedPasses[$effect->customer_class_pass_id] = $customerClassPass;
    }

    private function sessionsRequiredAfterRestore(CustomerClassPass $customerClassPass, ScheduledClassCancellationEffect $effect): int
    {
        $count = $customerClassPass->reservations()
            ->whereIn('status', [
                CustomerClassPassReservationStatus::Reserved->value,
                CustomerClassPassReservationStatus::Used->value,
            ])
            ->count();

        if (! $effect->customer_class_pass_reservation_id) {
            return $count;
        }

        $reservation = CustomerClassPassReservation::query()
            ->whereKey($effect->customer_class_pass_reservation_id)
            ->lockForUpdate()
            ->first();

        if (! $reservation) {
            throw ValidationException::withMessages([
                'scheduled_class' => __('app.scheduled_class_restore_reservation_missing'),
            ]);
        }

        $currentlyCounts = in_array($reservation->status, [
            CustomerClassPassReservationStatus::Reserved,
            CustomerClassPassReservationStatus::Used,
        ], true);
        $willCount = in_array($effect->previous_reservation_status, [
            CustomerClassPassReservationStatus::Reserved->value,
            CustomerClassPassReservationStatus::Used->value,
        ], true);

        return $count - ($currentlyCounts ? 1 : 0) + ($willCount ? 1 : 0);
    }
}

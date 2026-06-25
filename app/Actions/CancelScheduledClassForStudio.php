<?php

namespace App\Actions;

use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\ScheduledClassStatus;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassReservation;
use App\Models\ScheduledClass;
use App\Models\ScheduledClassCancellation;
use App\Models\ScheduledClassCancellationEffect;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelScheduledClassForStudio
{
    private const MAX_UNSIGNED_SMALL_INTEGER = 65535;

    public function __construct(private readonly NormalizeCustomerClassPasses $normalizeCustomerClassPasses) {}

    public function execute(Account $account, ScheduledClass $scheduledClass, ?User $user): ScheduledClassCancellation
    {
        return DB::transaction(function () use ($account, $scheduledClass, $user): ScheduledClassCancellation {
            $lockedClass = ScheduledClass::query()
                ->whereBelongsTo($account)
                ->whereKey($scheduledClass->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedClass->status === ScheduledClassStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'scheduled_class' => __('app.scheduled_class_already_cancelled'),
                ]);
            }

            $rules = $account->fresh()?->classPassCancellationRules() ?? Account::defaultClassPassCancellationRules();
            $cancellation = $lockedClass->cancellations()->create([
                'account_id' => $account->id,
                'cancelled_by_user_id' => $user?->id,
                'previous_scheduled_class_status' => $lockedClass->status->value,
                'rules_snapshot' => $rules,
                'cancelled_at' => now(),
            ]);

            ClassBooking::query()
                ->whereBelongsTo($account)
                ->where('scheduled_class_id', $lockedClass->id)
                ->where('status', ClassBookingStatus::Booked->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->each(fn (ClassBooking $booking): ScheduledClassCancellationEffect => $this->cancelBooking($cancellation, $booking, $lockedClass, $rules));

            $lockedClass->forceFill([
                'status' => ScheduledClassStatus::Cancelled->value,
                'is_manually_modified' => true,
            ])->save();

            return $cancellation->load('effects');
        });
    }

    /**
     * @param  array{return_sessions_enabled: bool, return_sessions_count: int, extend_days_enabled: bool, extend_days_count: int}  $rules
     */
    private function cancelBooking(ScheduledClassCancellation $cancellation, ClassBooking $booking, ScheduledClass $scheduledClass, array $rules): ScheduledClassCancellationEffect
    {
        $reservation = $booking->classPassReservation()->lockForUpdate()->first();
        $customerClassPass = $reservation
            ? CustomerClassPass::query()->whereKey($reservation->customer_class_pass_id)->lockForUpdate()->first()
            : null;
        $effectAttributes = $this->baseEffectAttributes($cancellation, $booking, $reservation);

        $booking->forceFill([
            'status' => ClassBookingStatus::Cancelled->value,
            'attended_at' => null,
        ])->save();

        if ($reservation && $customerClassPass) {
            $effectAttributes += $this->applyReservationAndPassRules($reservation, $customerClassPass, $scheduledClass, $rules);
            $this->normalizeCustomerClassPasses->forPass($customerClassPass->refresh());
        }

        return $cancellation->effects()->create($effectAttributes + [
            'account_id' => $cancellation->account_id,
            'class_booking_id' => $booking->id,
            'customer_class_pass_id' => $customerClassPass?->id,
            'customer_class_pass_reservation_id' => $reservation?->id,
            'new_booking_status' => ClassBookingStatus::Cancelled->value,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseEffectAttributes(ScheduledClassCancellation $cancellation, ClassBooking $booking, ?CustomerClassPassReservation $reservation): array
    {
        return [
            'scheduled_class_cancellation_id' => $cancellation->id,
            'previous_booking_status' => $booking->status->value,
            'previous_reservation_status' => $reservation?->status->value,
            'previous_reserved_at' => $reservation?->reserved_at,
            'previous_used_at' => $reservation?->used_at,
            'previous_released_at' => $reservation?->released_at,
        ];
    }

    /**
     * @param  array{return_sessions_enabled: bool, return_sessions_count: int, extend_days_enabled: bool, extend_days_count: int}  $rules
     * @return array<string, mixed>
     */
    private function applyReservationAndPassRules(CustomerClassPassReservation $reservation, CustomerClassPass $customerClassPass, ScheduledClass $scheduledClass, array $rules): array
    {
        $attributes = [];
        $now = now();
        $returnSessionsCount = $rules['return_sessions_enabled'] ? $rules['return_sessions_count'] : 0;
        $extendDaysCount = $rules['extend_days_enabled'] ? $rules['extend_days_count'] : 0;

        if ($returnSessionsCount > 0) {
            $reservation->forceFill([
                'status' => CustomerClassPassReservationStatus::Released->value,
                'used_at' => null,
                'released_at' => $now,
            ])->save();
        } else {
            $usedAt = $scheduledClass->starts_at instanceof Carbon ? $scheduledClass->starts_at : $now;
            $reservation->forceFill([
                'status' => CustomerClassPassReservationStatus::Used->value,
                'used_at' => $usedAt,
                'released_at' => null,
            ])->save();
        }

        $reservation->refresh();
        $attributes += [
            'new_reservation_status' => $reservation->status->value,
            'new_reserved_at' => $reservation->reserved_at,
            'new_used_at' => $reservation->used_at,
            'new_released_at' => $reservation->released_at,
        ];

        $addedSessionsCount = max(0, $returnSessionsCount - 1);
        $passUpdates = [];

        if ($addedSessionsCount > 0) {
            $previousSessionsCount = (int) $customerClassPass->sessions_count;
            $newSessionsCount = $this->checkedSmallInteger($previousSessionsCount + $addedSessionsCount, 'return_sessions_count');
            $passUpdates['sessions_count'] = $newSessionsCount;
            $attributes += [
                'added_sessions_count' => $addedSessionsCount,
                'previous_sessions_count' => $previousSessionsCount,
                'new_sessions_count' => $newSessionsCount,
            ];
        }

        if ($extendDaysCount > 0) {
            $previousValidityDays = (int) $customerClassPass->validity_days;
            $previousTotalValidityDays = (int) $customerClassPass->total_validity_days;
            $newValidityDays = $this->checkedSmallInteger($previousValidityDays + $extendDaysCount, 'extend_days_count');
            $newTotalValidityDays = $this->checkedSmallInteger($previousTotalValidityDays + $extendDaysCount, 'extend_days_count');

            $passUpdates += [
                'validity_days' => $newValidityDays,
                'total_validity_days' => $newTotalValidityDays,
            ];
            $attributes += [
                'added_validity_days' => $extendDaysCount,
                'previous_validity_days' => $previousValidityDays,
                'new_validity_days' => $newValidityDays,
                'previous_total_validity_days' => $previousTotalValidityDays,
                'new_total_validity_days' => $newTotalValidityDays,
            ];
        }

        if ($passUpdates !== []) {
            $customerClassPass->forceFill($passUpdates)->save();
        }

        return $attributes;
    }

    private function checkedSmallInteger(int $value, string $field): int
    {
        if ($value > self::MAX_UNSIGNED_SMALL_INTEGER) {
            throw ValidationException::withMessages([
                $field => __('app.class_pass_compensation_too_large'),
            ]);
        }

        return $value;
    }
}

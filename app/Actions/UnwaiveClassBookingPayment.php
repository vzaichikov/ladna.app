<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassBookingPaymentWaiver;
use App\Models\User;
use App\Support\ActorSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UnwaiveClassBookingPayment
{
    public function __construct(private readonly ActorSnapshot $actorSnapshot) {}

    public function execute(
        Account $account,
        ClassBookingPaymentWaiver $classBookingPaymentWaiver,
        User $user,
        string $reason,
    ): ClassBookingPaymentWaiver {
        abort_unless($account->isOwnedBy($user), 403);
        abort_unless($classBookingPaymentWaiver->account_id === $account->id, 404);

        validator(
            ['reason' => $reason],
            ['reason' => ['required', 'string', 'min:3', 'max:2000']],
        )->validate();

        return DB::transaction(function () use ($account, $classBookingPaymentWaiver, $user, $reason): ClassBookingPaymentWaiver {
            if ($classBookingPaymentWaiver->class_booking_id === null) {
                throw ValidationException::withMessages([
                    'reason' => __('app.class_booking_payment_unwaive_booking_missing'),
                ]);
            }

            $lockedBooking = ClassBooking::query()
                ->with([
                    'classPassReservation.customerClassPass',
                    'manualCashPayment',
                    'scheduledClass.classType',
                ])
                ->whereBelongsTo($account)
                ->whereKey($classBookingPaymentWaiver->class_booking_id)
                ->lockForUpdate()
                ->first();

            if (! $lockedBooking) {
                throw ValidationException::withMessages([
                    'reason' => __('app.class_booking_payment_unwaive_booking_missing'),
                ]);
            }

            $lockedWaiver = ClassBookingPaymentWaiver::query()
                ->whereBelongsTo($account)
                ->whereBelongsTo($lockedBooking, 'classBooking')
                ->whereKey($classBookingPaymentWaiver->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedWaiver->isActive()) {
                throw ValidationException::withMessages([
                    'reason' => __('app.class_booking_payment_already_unwaived'),
                ]);
            }

            $dueKind = $lockedBooking->manualCashPaymentRequirementKind($lockedBooking->scheduledClass);

            if ($dueKind !== $lockedWaiver->payment_due_kind) {
                throw ValidationException::withMessages([
                    'reason' => __('app.class_booking_payment_unwaive_not_due'),
                ]);
            }

            $lockedWaiver->forceFill([
                'unwaived_at' => now(),
                'unwaive_reason' => $reason,
                ...$this->actorSnapshot->prefixed($account, $user, 'unwaived_by_actor'),
            ])->save();

            return $lockedWaiver;
        }, attempts: 5);
    }
}

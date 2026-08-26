<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassBookingPaymentWaiver;
use App\Models\User;
use App\Support\ActorSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WaiveClassBookingPayment
{
    public function __construct(private readonly ActorSnapshot $actorSnapshot) {}

    public function execute(
        Account $account,
        ClassBooking $classBooking,
        User $user,
        string $reason,
    ): ClassBookingPaymentWaiver {
        abort_unless($account->isOwnedBy($user), 403);
        abort_unless($classBooking->account_id === $account->id, 404);

        validator(
            ['reason' => $reason],
            ['reason' => ['required', 'string', 'min:3', 'max:2000']],
        )->validate();

        return DB::transaction(function () use ($account, $classBooking, $user, $reason): ClassBookingPaymentWaiver {
            $lockedBooking = ClassBooking::query()
                ->with([
                    'activePaymentWaiver',
                    'classPassReservation.customerClassPass',
                    'customer',
                    'manualCashPayment',
                    'scheduledClass.classType',
                    'scheduledClass.location',
                    'scheduledClass.room',
                ])
                ->whereBelongsTo($account)
                ->whereKey($classBooking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBooking->activePaymentWaiver) {
                throw ValidationException::withMessages([
                    'reason' => __('app.class_booking_payment_already_waived'),
                ]);
            }

            $dueKind = $lockedBooking->manualCashPaymentRequirementKind($lockedBooking->scheduledClass);

            if ($dueKind === null) {
                throw ValidationException::withMessages([
                    'reason' => __('app.class_booking_payment_not_waivable'),
                ]);
            }

            $scheduledClass = $lockedBooking->scheduledClass;
            $reservedClassPass = $lockedBooking->activeClassPassReservation()?->customerClassPass;
            $amountCents = $dueKind === ClassBooking::ManualPaymentDueAnyTimeAddon
                ? $lockedBooking->anyTimeAddonAmountCents()
                : null;
            $waivedAt = now();

            return $lockedBooking->paymentWaivers()->create([
                'account_id' => $account->id,
                'customer_class_pass_id' => $reservedClassPass?->id,
                'payment_due_kind' => $dueKind,
                'amount_cents' => $amountCents,
                'currency' => $reservedClassPass?->currency ?? $account->default_currency,
                'customer_name' => $lockedBooking->customer?->name ?? __('app.customer'),
                'scheduled_class_title' => $scheduledClass?->displayTitle() ?? __('app.class'),
                'scheduled_class_starts_at' => $scheduledClass?->starts_at ?? $waivedAt,
                'scheduled_class_timezone' => $scheduledClass?->displayTimezone() ?? $account->timezone,
                'location_name' => $scheduledClass?->location?->name,
                'room_name' => $scheduledClass?->room?->name,
                'customer_class_pass_code' => $reservedClassPass?->code,
                'reason' => $reason,
                'waived_at' => $waivedAt,
                ...$this->actorSnapshot->prefixed($account, $user, 'waived_by_actor'),
            ]);
        }, attempts: 5);
    }
}

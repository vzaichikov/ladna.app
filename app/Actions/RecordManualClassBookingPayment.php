<?php

namespace App\Actions;

use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\CustomerPurchase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordManualClassBookingPayment
{
    public function execute(Account $account, ClassBooking $classBooking, int $amountCents): CustomerPurchase
    {
        if ($classBooking->account_id !== $account->id) {
            abort(404);
        }

        if ($amountCents <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('app.class_pass_payment_amount_required'),
            ]);
        }

        return DB::transaction(function () use ($account, $classBooking, $amountCents): CustomerPurchase {
            $lockedBooking = ClassBooking::query()
                ->with(['scheduledClass.location', 'scheduledClass.room', 'scheduledClass.classType', 'customer'])
                ->whereBelongsTo($account)
                ->whereKey($classBooking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBooking->scheduledClass?->classType?->schedule_kind !== ScheduleKind::RoomRental) {
                throw ValidationException::withMessages([
                    'amount' => __('app.class_booking_payment_rental_only'),
                ]);
            }

            $hasActivePassReservation = $lockedBooking->classPassReservation()
                ->whereIn('status', [
                    CustomerClassPassReservationStatus::Reserved->value,
                    CustomerClassPassReservationStatus::Used->value,
                ])
                ->exists();

            if ($hasActivePassReservation) {
                throw ValidationException::withMessages([
                    'amount' => __('app.class_booking_payment_class_pass_reserved'),
                ]);
            }

            $scheduledClass = $lockedBooking->scheduledClass;
            $paidAt = $scheduledClass?->starts_at?->lessThan(now()) ? $scheduledClass->starts_at : now();
            $payment = $lockedBooking->manualCashPayment()->lockForUpdate()->first();
            $attributes = [
                'account_id' => $account->id,
                'customer_id' => $lockedBooking->customer_id,
                'location_id' => $scheduledClass?->location_id,
                'class_pass_plan_id' => null,
                'customer_class_pass_id' => null,
                'class_booking_id' => $lockedBooking->id,
                'provider' => CustomerPurchase::ProviderStudioCash,
                'payment_source' => CustomerPurchase::SourceManualCashBooking,
                'status' => CustomerPurchaseStatus::PaymentPaid->value,
                'plan_name' => $this->paymentName($lockedBooking),
                'plan_slug' => null,
                'schedule_kind' => ScheduleKind::RoomRental->value,
                'amount_cents' => $amountCents,
                'currency' => $account->default_currency,
                'sessions_count' => 1,
                'validity_days' => 1,
                'total_validity_days' => 1,
                'gateway_invoice_id' => null,
                'gateway_payment_id' => null,
                'gateway_status' => null,
                'gateway_checkout_payload' => null,
                'last_callback_payload' => null,
                'failure_reason' => null,
                'started_at' => $payment?->started_at ?? $paidAt,
                'paid_at' => $paidAt,
                'failed_at' => null,
                'expires_at' => null,
            ];

            if ($payment) {
                $payment->forceFill($attributes)->save();

                return $payment->refresh();
            }

            return CustomerPurchase::query()->create([
                ...$attributes,
                'order_id' => $this->orderId(),
            ]);
        });
    }

    private function paymentName(ClassBooking $classBooking): string
    {
        $scheduledClass = $classBooking->scheduledClass;

        return collect([
            $scheduledClass?->title,
            $scheduledClass?->room?->name,
        ])
            ->filter()
            ->join(' · ');
    }

    private function orderId(): string
    {
        do {
            $orderId = 'CASH-BOOKING-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
        } while (CustomerPurchase::query()->where('order_id', $orderId)->exists());

        return $orderId;
    }
}

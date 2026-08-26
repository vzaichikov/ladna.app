<?php

namespace App\Actions;

use App\Enums\CustomerPurchaseStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\CustomerPurchase;
use App\Models\StudioCashEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordManualClassBookingPayment
{
    public function __construct(private readonly RecordStudioCashEntry $recordStudioCashEntry) {}

    public function execute(
        Account $account,
        ClassBooking $classBooking,
        int $amountCents,
        ?User $user = null,
        ?string $idempotencyKey = null,
    ): CustomerPurchase {
        if ($classBooking->account_id !== $account->id) {
            abort(404);
        }

        if ($amountCents <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('app.class_pass_payment_amount_required'),
            ]);
        }

        return DB::transaction(function () use ($account, $classBooking, $amountCents, $user, $idempotencyKey): CustomerPurchase {
            $lockedBooking = ClassBooking::query()
                ->with(['activePaymentWaiver', 'scheduledClass.location', 'scheduledClass.room', 'scheduledClass.classType', 'customer', 'classPassReservation.customerClassPass'])
                ->whereBelongsTo($account)
                ->whereKey($classBooking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBooking->activePaymentWaiver) {
                throw ValidationException::withMessages([
                    'amount' => __('app.class_booking_payment_waived_cannot_record'),
                ]);
            }

            $anyTimeAddonAmountCents = $lockedBooking->anyTimeAddonAmountCents();
            $isAnyTimeAddonPayment = $anyTimeAddonAmountCents !== null && $anyTimeAddonAmountCents > 0;
            $activeReservation = $lockedBooking->activeClassPassReservation();
            $reservedClassPass = $activeReservation?->customerClassPass;

            if ($activeReservation && ! $isAnyTimeAddonPayment) {
                throw ValidationException::withMessages([
                    'amount' => __('app.class_booking_payment_class_pass_reserved'),
                ]);
            }

            if ($isAnyTimeAddonPayment && $amountCents !== $anyTimeAddonAmountCents) {
                throw ValidationException::withMessages([
                    'amount' => __('app.any_time_addon_payment_amount_mismatch'),
                ]);
            }

            $scheduledClass = $lockedBooking->scheduledClass;
            $paidAt = now();
            $orderId = $this->orderId($idempotencyKey);
            $idempotentPayment = $idempotencyKey
                ? CustomerPurchase::query()
                    ->where('order_id', $orderId)
                    ->first()
                : null;

            if ($idempotentPayment) {
                if ($idempotentPayment->account_id !== $account->id
                    || $idempotentPayment->class_booking_id !== $lockedBooking->id
                    || $idempotentPayment->amount_cents !== $amountCents
                    || $idempotentPayment->location_id !== $scheduledClass?->location_id) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => __('app.payment_refund_duplicate_request'),
                    ]);
                }

                return $idempotentPayment;
            }

            $payment = $lockedBooking->manualCashPayment()->lockForUpdate()->first();

            if ($payment) {
                if ((int) $payment->amount_cents === $amountCents) {
                    return $payment;
                }

                throw ValidationException::withMessages([
                    'amount' => __('app.payment_correction_required'),
                ]);
            }
            $attributes = [
                'account_id' => $account->id,
                'customer_id' => $lockedBooking->customer_id,
                'location_id' => $scheduledClass?->location_id,
                'class_pass_plan_id' => $isAnyTimeAddonPayment ? $reservedClassPass?->class_pass_plan_id : null,
                'customer_class_pass_id' => $isAnyTimeAddonPayment ? $reservedClassPass?->id : null,
                'class_booking_id' => $lockedBooking->id,
                'provider' => CustomerPurchase::ProviderStudioCash,
                'payment_source' => CustomerPurchase::SourceManualCashBooking,
                'status' => CustomerPurchaseStatus::PaymentPaid->value,
                'plan_name' => $this->paymentName($lockedBooking, $isAnyTimeAddonPayment),
                'plan_slug' => null,
                'schedule_kind' => $scheduledClass?->classType?->schedule_kind?->value ?? ScheduleKind::RoomRental->value,
                'amount_cents' => $amountCents,
                'currency' => $account->default_currency,
                'sessions_count' => $isAnyTimeAddonPayment ? 0 : 1,
                'validity_days' => 1,
                'total_validity_days' => 1,
                'gateway_invoice_id' => null,
                'gateway_payment_id' => null,
                'gateway_status' => null,
                'gateway_checkout_payload' => null,
                'last_callback_payload' => null,
                'failure_reason' => null,
                'started_at' => $paidAt,
                'paid_at' => $paidAt,
                'failed_at' => null,
                'expires_at' => null,
            ];

            $payment = CustomerPurchase::query()->create([
                ...$attributes,
                'order_id' => $orderId,
            ]);

            $this->recordStudioCashEntry->execute(
                $account,
                $scheduledClass?->location,
                StudioCashEntry::DirectionIn,
                $amountCents,
                $paidAt,
                $user,
                $payment->plan_name,
                StudioCashEntry::PurposeCustomerPayment,
                purchase: $payment,
                currency: $payment->currency,
                sourceKey: 'purchase:'.$payment->id.':cash-in',
            );

            return $payment;
        }, attempts: 5);
    }

    private function paymentName(ClassBooking $classBooking, bool $isAnyTimeAddonPayment): string
    {
        $scheduledClass = $classBooking->scheduledClass;

        if ($isAnyTimeAddonPayment) {
            return collect([
                __('app.any_time_addon_payment'),
                $scheduledClass?->title,
            ])
                ->filter()
                ->join(' · ');
        }

        return collect([
            $scheduledClass?->title,
            $scheduledClass?->room?->name,
        ])
            ->filter()
            ->join(' · ');
    }

    private function orderId(?string $idempotencyKey): string
    {
        if ($idempotencyKey) {
            return 'CASH-BOOKING-'.$idempotencyKey;
        }

        do {
            $orderId = 'CASH-BOOKING-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
        } while (CustomerPurchase::query()->where('order_id', $orderId)->exists());

        return $orderId;
    }
}

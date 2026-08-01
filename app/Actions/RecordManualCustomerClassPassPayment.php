<?php

namespace App\Actions;

use App\Enums\CustomerPurchaseStatus;
use App\Models\Account;
use App\Models\CustomerClassPass;
use App\Models\CustomerPurchase;
use App\Models\Location;
use App\Models\StudioCashEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordManualCustomerClassPassPayment
{
    public function __construct(private readonly RecordStudioCashEntry $recordStudioCashEntry) {}

    public function execute(
        Account $account,
        CustomerClassPass $customerClassPass,
        Location $location,
        int $amountCents,
        ?Carbon $paidAt = null,
        ?User $user = null,
        ?string $idempotencyKey = null,
    ): CustomerPurchase {
        if ($customerClassPass->account_id !== $account->id || $location->account_id !== $account->id) {
            abort(404);
        }

        if ($customerClassPass->source !== 'manual') {
            throw ValidationException::withMessages([
                'amount' => __('app.class_pass_manual_payment_only'),
            ]);
        }

        if ($amountCents <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('app.class_pass_payment_amount_required'),
            ]);
        }

        return DB::transaction(function () use ($account, $customerClassPass, $location, $amountCents, $paidAt, $user, $idempotencyKey): CustomerPurchase {
            $lockedClassPass = CustomerClassPass::query()
                ->with('classPassPlan')
                ->whereKey($customerClassPass->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedClassPass->account_id !== $account->id) {
                abort(404);
            }

            $orderId = $this->orderId($idempotencyKey);
            $existingPayment = CustomerPurchase::query()
                ->whereBelongsTo($account)
                ->where('order_id', $orderId)
                ->first();

            if ($existingPayment) {
                if ($existingPayment->customer_class_pass_id !== $lockedClassPass->id
                    || $existingPayment->location_id !== $location->id
                    || $existingPayment->amount_cents !== $amountCents
                    || $existingPayment->currency !== $lockedClassPass->currency) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => __('app.payment_refund_duplicate_request'),
                    ]);
                }

                return $existingPayment;
            }

            $remainingCents = $lockedClassPass->remainingPaymentCents();

            if ($remainingCents <= 0 || $amountCents > $remainingCents) {
                throw ValidationException::withMessages([
                    'amount' => __('app.class_pass_payment_amount_too_high'),
                ]);
            }

            $paidAt ??= now();
            $newPaidAmountCents = min((int) $lockedClassPass->price_cents, $lockedClassPass->paidAmountCents() + $amountCents);

            $payment = CustomerPurchase::query()->create([
                'account_id' => $lockedClassPass->account_id,
                'customer_id' => $lockedClassPass->customer_id,
                'location_id' => $location->id,
                'class_pass_plan_id' => $lockedClassPass->class_pass_plan_id,
                'customer_class_pass_id' => $lockedClassPass->id,
                'provider' => CustomerPurchase::ProviderStudioCash,
                'payment_source' => CustomerPurchase::SourceManualCashClassPass,
                'order_id' => $orderId,
                'status' => CustomerPurchaseStatus::PaymentPaid->value,
                'plan_name' => $lockedClassPass->plan_name,
                'plan_slug' => $lockedClassPass->plan_slug,
                'schedule_kind' => $lockedClassPass->classPassPlan?->schedule_kind?->value ?? 'group_class',
                'amount_cents' => $amountCents,
                'currency' => $lockedClassPass->currency,
                'sessions_count' => $lockedClassPass->sessions_count,
                'validity_days' => $lockedClassPass->validity_days,
                'total_validity_days' => $lockedClassPass->total_validity_days,
                'started_at' => $paidAt,
                'paid_at' => $paidAt,
            ]);

            $lockedClassPass->forceFill([
                'paid_amount_cents' => $newPaidAmountCents,
                'is_paid' => $newPaidAmountCents >= (int) $lockedClassPass->price_cents,
                'issued_location_id' => $lockedClassPass->issued_location_id ?? $location->id,
            ])->save();

            $this->recordStudioCashEntry->execute(
                $account,
                $location,
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

    private function orderId(?string $idempotencyKey): string
    {
        if ($idempotencyKey) {
            return 'CASH-'.$idempotencyKey;
        }

        do {
            $orderId = 'CASH-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
        } while (CustomerPurchase::query()->where('order_id', $orderId)->exists());

        return $orderId;
    }
}

<?php

namespace App\Actions;

use App\Enums\CustomerPurchaseStatus;
use App\Models\Account;
use App\Models\CustomerClassPass;
use App\Models\CustomerPurchase;
use App\Models\Location;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordManualCustomerClassPassPayment
{
    public function execute(
        Account $account,
        CustomerClassPass $customerClassPass,
        Location $location,
        int $amountCents,
        ?Carbon $paidAt = null,
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

        return DB::transaction(function () use ($account, $customerClassPass, $location, $amountCents, $paidAt): CustomerPurchase {
            $lockedClassPass = CustomerClassPass::query()
                ->with('classPassPlan')
                ->whereKey($customerClassPass->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedClassPass->account_id !== $account->id) {
                abort(404);
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
                'order_id' => $this->orderId(),
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

            return $payment;
        });
    }

    private function orderId(): string
    {
        do {
            $orderId = 'CASH-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
        } while (CustomerPurchase::query()->where('order_id', $orderId)->exists());

        return $orderId;
    }
}

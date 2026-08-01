<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseCorrection;
use App\Models\Location;
use App\Models\StudioCashEntry;
use App\Models\User;
use App\Support\ActorSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CorrectCustomerPurchase
{
    public function __construct(
        private readonly ActorSnapshot $actorSnapshot,
        private readonly RecalculateCustomerClassPassPayment $recalculateCustomerClassPassPayment,
        private readonly RecordStudioCashEntry $recordStudioCashEntry,
    ) {}

    public function execute(
        Account $account,
        CustomerPurchase $customerPurchase,
        Location $location,
        int $amountCents,
        CarbonInterface $paidAt,
        ?User $user,
        string $reason,
        ?string $idempotencyKey = null,
    ): CustomerPurchaseCorrection {
        $idempotencyKey ??= (string) Str::uuid();
        validator(
            ['idempotency_key' => $idempotencyKey],
            ['idempotency_key' => ['required', 'uuid']],
        )->validate();

        return DB::transaction(function () use ($account, $customerPurchase, $location, $amountCents, $paidAt, $user, $reason, $idempotencyKey): CustomerPurchaseCorrection {
            $purchase = CustomerPurchase::query()
                ->with(['customerClassPass', 'fiscalReceipts', 'refunds'])
                ->whereBelongsTo($account)
                ->whereKey($customerPurchase->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($location->account_id !== $account->id) {
                abort(404);
            }

            $existingCorrection = CustomerPurchaseCorrection::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingCorrection) {
                $this->ensureIdempotentMatch(
                    $existingCorrection,
                    $account,
                    $purchase,
                    $location,
                    $amountCents,
                    $paidAt,
                    $reason,
                );

                return $existingCorrection;
            }

            if (! $purchase->canBeCorrectedAsStudioCash()) {
                throw ValidationException::withMessages([
                    'reason' => __('app.payment_correction_not_allowed'),
                ]);
            }

            if ($amountCents <= 0) {
                throw ValidationException::withMessages([
                    'amount' => __('app.class_pass_payment_amount_required'),
                ]);
            }

            if ($amountCents < $purchase->refundedAmountCents()) {
                throw ValidationException::withMessages([
                    'amount' => __('app.payment_correction_below_refunded_amount'),
                ]);
            }

            $correction = CustomerPurchaseCorrection::query()->create([
                'account_id' => $account->id,
                'customer_purchase_id' => $purchase->id,
                'previous_location_id' => $purchase->location_id,
                'new_location_id' => $location->id,
                'previous_amount_cents' => $purchase->amount_cents,
                'new_amount_cents' => $amountCents,
                'previous_paid_at' => $purchase->paid_at,
                'new_paid_at' => $paidAt,
                'idempotency_key' => $idempotencyKey,
                ...$this->actorSnapshot->capture($account, $user),
                'reason' => $reason,
            ]);

            $previousLocation = Location::query()
                ->whereBelongsTo($account)
                ->whereKey($purchase->location_id)
                ->firstOrFail();
            $correctedAt = now();

            $this->recordStudioCashEntry->execute(
                $account,
                $previousLocation,
                StudioCashEntry::DirectionOut,
                (int) $purchase->amount_cents,
                $correctedAt,
                $user,
                $reason,
                StudioCashEntry::PurposePaymentCorrectionReversal,
                purchase: $purchase,
                correction: $correction,
                currency: $purchase->currency,
                sourceKey: 'correction:'.$correction->id.':reversal',
            );
            $this->recordStudioCashEntry->execute(
                $account,
                $location,
                StudioCashEntry::DirectionIn,
                $amountCents,
                $correctedAt,
                $user,
                $reason,
                StudioCashEntry::PurposePaymentCorrection,
                purchase: $purchase,
                correction: $correction,
                currency: $purchase->currency,
                sourceKey: 'correction:'.$correction->id.':corrected',
            );

            $purchase->forceFill([
                'location_id' => $location->id,
                'amount_cents' => $amountCents,
                'paid_at' => $paidAt,
                'started_at' => $purchase->started_at ?? $paidAt,
            ])->save();

            if ($purchase->isManualCashClassPassPayment() && $purchase->customerClassPass) {
                $this->recalculateCustomerClassPassPayment->execute($purchase->customerClassPass);
            }

            return $correction;
        }, attempts: 5);
    }

    private function ensureIdempotentMatch(
        CustomerPurchaseCorrection $correction,
        Account $account,
        CustomerPurchase $purchase,
        Location $location,
        int $amountCents,
        CarbonInterface $paidAt,
        string $reason,
    ): void {
        if ($correction->account_id !== $account->id
            || $correction->customer_purchase_id !== $purchase->id
            || $correction->new_location_id !== $location->id
            || $correction->new_amount_cents !== $amountCents
            || ! $correction->new_paid_at->equalTo($paidAt)
            || $correction->reason !== $reason) {
            throw ValidationException::withMessages([
                'idempotency_key' => __('validation.unique', ['attribute' => 'idempotency key']),
            ]);
        }
    }
}

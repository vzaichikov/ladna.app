<?php

namespace App\Actions;

use App\Enums\CustomerPurchaseStatus;
use App\Models\Account;
use App\Models\CustomerClassPass;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseCorrection;
use App\Models\Location;
use App\Models\User;
use App\Support\ActorSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CorrectCustomerPurchase
{
    public function __construct(private readonly ActorSnapshot $actorSnapshot) {}

    public function execute(
        Account $account,
        CustomerPurchase $customerPurchase,
        Location $location,
        int $amountCents,
        CarbonInterface $paidAt,
        ?User $user,
        string $reason,
    ): CustomerPurchaseCorrection {
        return DB::transaction(function () use ($account, $customerPurchase, $location, $amountCents, $paidAt, $user, $reason): CustomerPurchaseCorrection {
            $purchase = CustomerPurchase::query()
                ->with(['customerClassPass', 'fiscalReceipts'])
                ->whereBelongsTo($account)
                ->whereKey($customerPurchase->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($location->account_id !== $account->id) {
                abort(404);
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

            $correction = CustomerPurchaseCorrection::query()->create([
                'account_id' => $account->id,
                'customer_purchase_id' => $purchase->id,
                'previous_location_id' => $purchase->location_id,
                'new_location_id' => $location->id,
                'previous_amount_cents' => $purchase->amount_cents,
                'new_amount_cents' => $amountCents,
                'previous_paid_at' => $purchase->paid_at,
                'new_paid_at' => $paidAt,
                ...$this->actorSnapshot->capture($account, $user),
                'reason' => $reason,
            ]);

            $purchase->forceFill([
                'location_id' => $location->id,
                'amount_cents' => $amountCents,
                'paid_at' => $paidAt,
                'started_at' => $purchase->started_at ?? $paidAt,
            ])->save();

            if ($purchase->isManualCashClassPassPayment() && $purchase->customerClassPass) {
                $this->recalculateClassPassPayment($purchase->customerClassPass);
            }

            return $correction;
        });
    }

    private function recalculateClassPassPayment(CustomerClassPass $customerClassPass): void
    {
        $paidAmountCents = CustomerPurchase::query()
            ->whereBelongsTo($customerClassPass)
            ->where('payment_source', CustomerPurchase::SourceManualCashClassPass)
            ->where('status', CustomerPurchaseStatus::PaymentPaid->value)
            ->sum('amount_cents');

        $normalizedPaidAmountCents = min((int) $customerClassPass->price_cents, (int) $paidAmountCents);

        $customerClassPass->forceFill([
            'paid_amount_cents' => $normalizedPaidAmountCents,
            'is_paid' => $normalizedPaidAmountCents >= (int) $customerClassPass->price_cents,
        ])->save();
    }
}

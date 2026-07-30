<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseRefund;
use App\Models\Location;
use App\Models\StudioCashEntry;
use App\Models\User;
use App\Support\ActorSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordCustomerPurchaseRefund
{
    public function __construct(
        private readonly ActorSnapshot $actorSnapshot,
        private readonly RecordStudioCashEntry $recordStudioCashEntry,
        private readonly RecalculateCustomerClassPassPayment $recalculateCustomerClassPassPayment,
    ) {}

    public function execute(
        Account $account,
        CustomerPurchase $customerPurchase,
        string $method,
        ?Location $cashLocation,
        int $amountCents,
        CarbonInterface $refundedAt,
        ?User $user,
        string $reason,
        string $idempotencyKey,
    ): CustomerPurchaseRefund {
        return DB::transaction(function () use ($account, $customerPurchase, $method, $cashLocation, $amountCents, $refundedAt, $user, $reason, $idempotencyKey): CustomerPurchaseRefund {
            $purchase = CustomerPurchase::query()
                ->with(['customerClassPass', 'refunds'])
                ->whereBelongsTo($account)
                ->whereKey($customerPurchase->id)
                ->lockForUpdate()
                ->firstOrFail();
            $existingRefund = CustomerPurchaseRefund::query()
                ->whereBelongsTo($account)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingRefund) {
                if ($existingRefund->customer_purchase_id !== $purchase->id) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => __('app.payment_refund_duplicate_request'),
                    ]);
                }

                return $existingRefund;
            }

            if ($cashLocation && $cashLocation->account_id !== $account->id) {
                abort(404);
            }

            if (! in_array($method, CustomerPurchaseRefund::methods(), true)) {
                throw ValidationException::withMessages([
                    'method' => __('app.payment_refund_method_invalid'),
                ]);
            }

            if ($method === CustomerPurchaseRefund::MethodCash && ! $cashLocation) {
                throw ValidationException::withMessages([
                    'cash_location_id' => __('app.payment_refund_cash_location_required'),
                ]);
            }

            if (! $purchase->isPaid()) {
                throw ValidationException::withMessages([
                    'amount' => __('app.payment_refund_not_allowed'),
                ]);
            }

            if ($amountCents <= 0 || $amountCents > $purchase->remainingRefundableAmountCents()) {
                throw ValidationException::withMessages([
                    'amount' => __('app.payment_refund_amount_exceeds_remaining'),
                ]);
            }

            $refund = CustomerPurchaseRefund::query()->create([
                'account_id' => $account->id,
                'customer_purchase_id' => $purchase->id,
                'location_id' => $method === CustomerPurchaseRefund::MethodCash
                    ? $cashLocation?->id
                    : $purchase->location_id,
                'cash_location_id' => $method === CustomerPurchaseRefund::MethodCash
                    ? $cashLocation?->id
                    : null,
                'method' => $method,
                'amount_cents' => $amountCents,
                'currency' => $purchase->currency,
                'refunded_at' => $refundedAt,
                'idempotency_key' => $idempotencyKey,
                ...$this->actorSnapshot->capture($account, $user),
                'reason' => $reason,
            ]);

            if ($refund->isCash()) {
                $this->recordStudioCashEntry->execute(
                    $account,
                    $cashLocation,
                    StudioCashEntry::DirectionOut,
                    $refund->amount_cents,
                    $refund->refunded_at,
                    $user,
                    $reason,
                    StudioCashEntry::PurposePaymentRefund,
                    refund: $refund,
                    currency: $refund->currency,
                );
            }

            if ($purchase->fundsCustomerClassPass() && $purchase->customerClassPass) {
                $this->recalculateCustomerClassPassPayment->execute($purchase->customerClassPass);
            }

            return $refund->load(['customerPurchase', 'location', 'cashLocation', 'cashEntry']);
        }, attempts: 5);
    }
}

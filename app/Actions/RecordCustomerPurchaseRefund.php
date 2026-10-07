<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseItem;
use App\Models\CustomerPurchaseRefund;
use App\Models\Location;
use App\Models\StudioCashEntry;
use App\Models\User;
use App\Support\ActorSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordCustomerPurchaseRefund
{
    public function __construct(
        private readonly ActorSnapshot $actorSnapshot,
        private readonly RecordStudioCashEntry $recordStudioCashEntry,
        private readonly RecalculateCustomerClassPassPayment $recalculateCustomerClassPassPayment,
    ) {}

    /**
     * @param  array<int, array{customer_purchase_item_id: int, amount_cents: int}>  $itemAllocations
     */
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
        array $itemAllocations = [],
    ): CustomerPurchaseRefund {
        $itemAllocations = $this->normalizeItemAllocations($itemAllocations);

        return DB::transaction(function () use ($account, $customerPurchase, $method, $cashLocation, $amountCents, $refundedAt, $user, $reason, $idempotencyKey, $itemAllocations): CustomerPurchaseRefund {
            $purchase = CustomerPurchase::query()
                ->with(['customerClassPass', 'refunds'])
                ->whereBelongsTo($account)
                ->whereKey($customerPurchase->id)
                ->lockForUpdate()
                ->firstOrFail();
            $existingRefund = CustomerPurchaseRefund::query()
                ->with('items')
                ->whereBelongsTo($account)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingRefund) {
                $expectedCashLocationId = $method === CustomerPurchaseRefund::MethodCash
                    ? $cashLocation?->id
                    : null;

                if ($existingRefund->account_id !== $account->id
                    || $existingRefund->customer_purchase_id !== $purchase->id
                    || $existingRefund->method !== $method
                    || $existingRefund->cash_location_id !== $expectedCashLocationId
                    || $existingRefund->amount_cents !== $amountCents
                    || $existingRefund->reason !== $reason
                    || $existingRefund->items->sortBy('customer_purchase_item_id')->map(fn ($item): array => [
                        'customer_purchase_item_id' => (int) $item->customer_purchase_item_id,
                        'amount_cents' => (int) $item->amount_cents,
                    ])->values()->all() !== $itemAllocations) {
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

            $purchaseItems = $purchase->items()
                ->with(['customerClassPass', 'refundItems'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->each(fn (CustomerPurchaseItem $item): CustomerPurchaseItem => $item->setRelation('purchase', $purchase))
                ->keyBy('id');
            $this->validateItemAllocations($purchase, $purchaseItems, $itemAllocations, $amountCents);

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

            foreach ($itemAllocations as $allocation) {
                $refund->items()->create([
                    'account_id' => $account->id,
                    ...$allocation,
                ]);
            }

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
                    sourceKey: 'refund:'.$refund->id,
                );
            }

            if ($purchase->fundsCustomerClassPass() && $purchase->customerClassPass) {
                $this->recalculateCustomerClassPassPayment->execute($purchase->customerClassPass);
            }

            foreach ($itemAllocations as $allocation) {
                $customerClassPass = $purchaseItems->get($allocation['customer_purchase_item_id'])?->customerClassPass;

                if ($customerClassPass) {
                    $this->recalculateCustomerClassPassPayment->execute($customerClassPass);
                }
            }

            return $refund->load(['customerPurchase', 'location', 'cashLocation', 'cashEntry', 'items.customerPurchaseItem.customerClassPass']);
        }, attempts: 5);
    }

    /**
     * @param  array<int, array{customer_purchase_item_id: int, amount_cents: int}>  $itemAllocations
     * @return array<int, array{customer_purchase_item_id: int, amount_cents: int}>
     */
    private function normalizeItemAllocations(array $itemAllocations): array
    {
        $allocations = collect($itemAllocations);

        if ($allocations->contains(fn (array $allocation): bool => ! is_int($allocation['customer_purchase_item_id'] ?? null)
            || ! is_int($allocation['amount_cents'] ?? null)
            || $allocation['customer_purchase_item_id'] <= 0
            || $allocation['amount_cents'] <= 0)
            || $allocations->pluck('customer_purchase_item_id')->unique()->count() !== $allocations->count()) {
            throw ValidationException::withMessages([
                'items' => __('app.payment_refund_items_invalid'),
            ]);
        }

        return $allocations->sortBy('customer_purchase_item_id')->values()->all();
    }

    /**
     * @param  Collection<int, CustomerPurchaseItem>  $purchaseItems
     * @param  array<int, array{customer_purchase_item_id: int, amount_cents: int}>  $itemAllocations
     */
    private function validateItemAllocations(CustomerPurchase $purchase, Collection $purchaseItems, array $itemAllocations, int $amountCents): void
    {
        if ($purchaseItems->isEmpty()) {
            if ($itemAllocations !== []) {
                throw ValidationException::withMessages([
                    'items' => __('app.payment_refund_items_invalid'),
                ]);
            }

            return;
        }

        if ($itemAllocations === []) {
            throw ValidationException::withMessages([
                'items' => __('app.payment_refund_items_required'),
            ]);
        }

        if ((int) collect($itemAllocations)->sum('amount_cents') !== $amountCents) {
            throw ValidationException::withMessages([
                'amount' => __('app.payment_refund_items_total_mismatch'),
            ]);
        }

        foreach ($itemAllocations as $allocation) {
            $item = $purchaseItems->get($allocation['customer_purchase_item_id']);

            if (! $item
                || $item->account_id !== $purchase->account_id
                || ! $item->customerClassPass
                || $item->customerClassPass->account_id !== $purchase->account_id
                || $item->customerClassPass->customer_id !== $purchase->customer_id
                || $item->currency !== $purchase->currency
                || $allocation['amount_cents'] > $item->remainingRefundableAmountCents()) {
                throw ValidationException::withMessages([
                    'items' => __('app.payment_refund_items_invalid'),
                ]);
            }
        }
    }
}

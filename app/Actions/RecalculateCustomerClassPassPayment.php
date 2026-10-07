<?php

namespace App\Actions;

use App\Enums\CustomerPurchaseStatus;
use App\Models\CustomerClassPass;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseRefund;
use App\Models\CustomerPurchaseRefundItem;

class RecalculateCustomerClassPassPayment
{
    public function execute(CustomerClassPass $customerClassPass): CustomerClassPass
    {
        $lockedClassPass = CustomerClassPass::query()
            ->with('purchaseItem')
            ->whereKey($customerClassPass->id)
            ->lockForUpdate()
            ->firstOrFail();
        $fundingPurchases = CustomerPurchase::query()
            ->where('account_id', $lockedClassPass->account_id)
            ->where('customer_class_pass_id', $lockedClassPass->id)
            ->whereIn('payment_source', [
                CustomerPurchase::SourceManualCashClassPass,
                CustomerPurchase::SourceOnlineCheckout,
                CustomerPurchase::SourceManualCardClassPass,
            ])
            ->where('status', CustomerPurchaseStatus::PaymentPaid->value);
        $paidAmountCents = (int) (clone $fundingPurchases)->sum('amount_cents');
        $refundedAmountCents = (int) CustomerPurchaseRefund::query()
            ->where('account_id', $lockedClassPass->account_id)
            ->whereIn('customer_purchase_id', (clone $fundingPurchases)->select('id'))
            ->sum('amount_cents');

        $purchaseItem = $lockedClassPass->purchaseItem;

        if ($purchaseItem && $purchaseItem->account_id === $lockedClassPass->account_id) {
            $purchaseItem->loadMissing('purchase');

            if ($purchaseItem->purchase?->account_id === $lockedClassPass->account_id
                && $purchaseItem->purchase->isPaid()) {
                $paidAmountCents += (int) $purchaseItem->amount_cents;
                $refundedAmountCents += (int) CustomerPurchaseRefundItem::query()
                    ->where('account_id', $lockedClassPass->account_id)
                    ->where('customer_purchase_item_id', $purchaseItem->id)
                    ->sum('amount_cents');
            }
        }

        $payableAmountCents = $lockedClassPass->payableAmountCents();
        $normalizedPaidAmountCents = min(
            $payableAmountCents,
            max(0, $paidAmountCents - $refundedAmountCents),
        );

        $lockedClassPass->forceFill([
            'paid_amount_cents' => $normalizedPaidAmountCents,
            'is_paid' => $normalizedPaidAmountCents >= $payableAmountCents,
        ])->save();

        return $lockedClassPass->refresh();
    }
}

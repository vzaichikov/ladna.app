<?php

namespace App\Actions;

use App\Enums\CustomerPurchaseStatus;
use App\Models\CustomerClassPass;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseRefund;

class RecalculateCustomerClassPassPayment
{
    public function execute(CustomerClassPass $customerClassPass): CustomerClassPass
    {
        $lockedClassPass = CustomerClassPass::query()
            ->whereKey($customerClassPass->id)
            ->lockForUpdate()
            ->firstOrFail();
        $fundingPurchases = CustomerPurchase::query()
            ->where('account_id', $lockedClassPass->account_id)
            ->where('customer_class_pass_id', $lockedClassPass->id)
            ->whereIn('payment_source', [
                CustomerPurchase::SourceManualCashClassPass,
                CustomerPurchase::SourceOnlineCheckout,
            ])
            ->where('status', CustomerPurchaseStatus::PaymentPaid->value);
        $paidAmountCents = (int) (clone $fundingPurchases)->sum('amount_cents');
        $refundedAmountCents = (int) CustomerPurchaseRefund::query()
            ->where('account_id', $lockedClassPass->account_id)
            ->whereIn('customer_purchase_id', (clone $fundingPurchases)->select('id'))
            ->sum('amount_cents');
        $normalizedPaidAmountCents = min(
            (int) $lockedClassPass->price_cents,
            max(0, $paidAmountCents - $refundedAmountCents),
        );

        $lockedClassPass->forceFill([
            'paid_amount_cents' => $normalizedPaidAmountCents,
            'is_paid' => $normalizedPaidAmountCents >= (int) $lockedClassPass->price_cents,
        ])->save();

        return $lockedClassPass->refresh();
    }
}

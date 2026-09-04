<?php

namespace App\Actions\Payments;

use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\Location;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;

class CompleteFreeCustomerPurchase
{
    public function __construct(
        private readonly CreateCustomerPurchase $createCustomerPurchase,
        private readonly CompleteCustomerPurchase $completeCustomerPurchase,
    ) {}

    public function execute(
        Account $account,
        Customer $customer,
        ClassPassPlan $classPassPlan,
        Location $location,
        ?string $promoCode = null,
    ): CustomerPurchase {
        $purchase = $this->createCustomerPurchase->execute(
            $account,
            $customer,
            $classPassPlan,
            CustomerPurchase::ProviderFree,
            $location,
            $promoCode,
        );

        return $this->completeCustomerPurchase->execute($purchase, new PaymentCallbackResult(
            orderId: $purchase->order_id,
            status: PaymentCallbackStatus::Paid,
            gatewayStatus: CustomerPurchase::ProviderFree,
            amountCents: 0,
            currency: $purchase->currency,
            paidAt: now(),
        ));
    }
}

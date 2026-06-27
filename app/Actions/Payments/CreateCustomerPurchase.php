<?php

namespace App\Actions\Payments;

use App\Enums\IntegrationProvider;
use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\Location;
use Illuminate\Support\Str;

class CreateCustomerPurchase
{
    public function execute(
        Account $account,
        Customer $customer,
        ClassPassPlan $classPassPlan,
        IntegrationProvider $provider,
        ?Location $location = null,
    ): CustomerPurchase {
        if ($customer->account_id !== $account->id || $classPassPlan->account_id !== $account->id) {
            abort(404);
        }

        if ($location && $location->account_id !== $account->id) {
            abort(404);
        }

        return $account->customerPurchases()->create([
            'customer_id' => $customer->id,
            'location_id' => $location?->id,
            'class_pass_plan_id' => $classPassPlan->id,
            'provider' => $provider->value,
            'payment_source' => CustomerPurchase::SourceOnlineCheckout,
            'order_id' => $this->orderId($provider),
            'status' => 'payment_started',
            'plan_name' => $classPassPlan->name,
            'plan_slug' => $classPassPlan->slug,
            'schedule_kind' => $classPassPlan->schedule_kind->value,
            'amount_cents' => $classPassPlan->price_cents,
            'currency' => $classPassPlan->currency,
            'sessions_count' => $classPassPlan->sessions_count,
            'validity_days' => $classPassPlan->validity_days,
            'total_validity_days' => $classPassPlan->total_validity_days,
            'started_at' => now(),
        ]);
    }

    private function orderId(IntegrationProvider $provider): string
    {
        return Str::upper(Str::substr($provider->value, 0, 3)).'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(10));
    }
}

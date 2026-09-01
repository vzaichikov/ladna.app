<?php

namespace App\Actions\Payments;

use App\Enums\IntegrationProvider;
use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\Location;
use App\Support\ScheduleKindRegistry;
use App\Support\TrialClassPassEligibility;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateCustomerPurchase
{
    public function __construct(private readonly TrialClassPassEligibility $trialEligibility) {}

    public function execute(
        Account $account,
        Customer $customer,
        ClassPassPlan $classPassPlan,
        IntegrationProvider|string $provider,
        ?Location $location = null,
    ): CustomerPurchase {
        if ($customer->account_id !== $account->id || $classPassPlan->account_id !== $account->id) {
            abort(404);
        }

        if ($location && $location->account_id !== $account->id) {
            abort(404);
        }

        if (! $account->hasScheduleKindEnabled($classPassPlan->schedule_kind)
            || ! ScheduleKindRegistry::hasCapability($classPassPlan->schedule_kind, 'class_pass_eligible')) {
            abort(404);
        }

        $providerValue = $provider instanceof IntegrationProvider ? $provider->value : $provider;

        if ($providerValue === '' || (! ($provider instanceof IntegrationProvider) && $providerValue !== CustomerPurchase::ProviderFree)) {
            throw new InvalidArgumentException('Unsupported customer purchase provider.');
        }

        $startedAt = now();
        $trialEligibilityValidatedAt = $classPassPlan->is_trial ? $startedAt->copy() : null;

        $this->trialEligibility->assertAvailable(
            $account,
            $customer,
            $classPassPlan,
            TrialClassPassEligibility::SourceOnlinePayment,
            $trialEligibilityValidatedAt,
        );

        return $account->customerPurchases()->create([
            'customer_id' => $customer->id,
            'location_id' => $location?->id,
            'class_pass_plan_id' => $classPassPlan->id,
            'provider' => $providerValue,
            'payment_source' => CustomerPurchase::SourceOnlineCheckout,
            'order_id' => $this->orderId($providerValue),
            'status' => 'payment_started',
            'plan_name' => $classPassPlan->name,
            'plan_slug' => $classPassPlan->slug,
            'schedule_kind' => $classPassPlan->schedule_kind->value,
            'amount_cents' => $classPassPlan->price_cents,
            'currency' => $classPassPlan->currency,
            'sessions_count' => $classPassPlan->sessions_count,
            'validity_days' => $classPassPlan->validity_days,
            'total_validity_days' => $classPassPlan->total_validity_days,
            'started_at' => $startedAt,
            'trial_eligibility_validated_at' => $trialEligibilityValidatedAt,
        ]);
    }

    private function orderId(string $provider): string
    {
        return Str::upper(Str::substr($provider, 0, 3)).'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(10));
    }
}

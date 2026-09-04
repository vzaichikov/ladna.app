<?php

namespace App\Actions\Payments;

use App\Enums\IntegrationProvider;
use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\Location;
use App\Support\Promotions\StudioPromoCodeService;
use App\Support\ScheduleKindRegistry;
use App\Support\TrialClassPassEligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateCustomerPurchase
{
    public function __construct(
        private readonly TrialClassPassEligibility $trialEligibility,
        private readonly StudioPromoCodeService $promoCodes,
    ) {}

    public function execute(
        Account $account,
        Customer $customer,
        ClassPassPlan $classPassPlan,
        IntegrationProvider|string $provider,
        ?Location $location = null,
        ?string $promoCode = null,
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

        return DB::transaction(function () use ($account, $customer, $classPassPlan, $providerValue, $location, $promoCode): CustomerPurchase {
            $lockedClassPassPlan = ClassPassPlan::query()
                ->whereBelongsTo($account)
                ->whereKey($classPassPlan->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $startedAt = now();
            $trialEligibilityValidatedAt = $lockedClassPassPlan->is_trial ? $startedAt->copy() : null;

            $this->trialEligibility->assertAvailable(
                $account,
                $customer,
                $lockedClassPassPlan,
                TrialClassPassEligibility::SourceOnlinePayment,
                $trialEligibilityValidatedAt,
            );

            $promotion = filled($promoCode)
                ? $this->promoCodes->quote($account, $lockedClassPassPlan, $customer, (string) $promoCode, true)
                : null;
            $amountCents = $promotion ? $promotion['quote']->totalCents : (int) $lockedClassPassPlan->price_cents;

            if ($providerValue === CustomerPurchase::ProviderFree && $amountCents !== 0) {
                throw new InvalidArgumentException('Free checkout requires a zero final amount.');
            }

            return $account->customerPurchases()->create([
                'customer_id' => $customer->id,
                'location_id' => $location?->id,
                'class_pass_plan_id' => $lockedClassPassPlan->id,
                'studio_promo_code_id' => $promotion ? $promotion['promoCode']->id : null,
                'provider' => $providerValue,
                'payment_source' => CustomerPurchase::SourceOnlineCheckout,
                'order_id' => $this->orderId($providerValue),
                'status' => 'payment_started',
                'plan_name' => $lockedClassPassPlan->name,
                'plan_slug' => $lockedClassPassPlan->slug,
                'schedule_kind' => $lockedClassPassPlan->schedule_kind->value,
                'amount_cents' => $amountCents,
                'subtotal_cents' => $lockedClassPassPlan->price_cents,
                'discount_cents' => $promotion ? $promotion['quote']->discountCents : 0,
                'currency' => $lockedClassPassPlan->currency,
                'promo_name' => $promotion ? $promotion['promoCode']->name : null,
                'promo_code' => $promotion ? $promotion['promoCode']->code : null,
                'promo_discount_type' => $promotion ? $promotion['promoCode']->discount_type->value : null,
                'promo_discount_value' => $promotion ? $promotion['promoCode']->discount_value : null,
                'promo_email_hash' => $promotion['emailHash'] ?? null,
                'promo_phone_hash' => $promotion['phoneHash'] ?? null,
                'sessions_count' => $lockedClassPassPlan->sessions_count,
                'validity_days' => $lockedClassPassPlan->validity_days,
                'total_validity_days' => $lockedClassPassPlan->total_validity_days,
                'started_at' => $startedAt,
                'expires_at' => $startedAt->copy()->addHour(),
                'trial_eligibility_validated_at' => $trialEligibilityValidatedAt,
            ]);
        }, 3);
    }

    private function orderId(string $provider): string
    {
        return Str::upper(Str::substr($provider, 0, 3)).'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(10));
    }
}

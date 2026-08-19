<?php

namespace App\Support;

use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\Location;
use Illuminate\Contracts\Session\Session;

class PublicClassPassCheckoutContext
{
    private const SessionKey = 'public_class_pass_checkout';

    private const LifetimeMinutes = 120;

    public function __construct(private readonly Session $session) {}

    public function remember(Account $account, Location $location, ClassPassPlan $classPassPlan): void
    {
        $current = $this->dataFor($account);
        $sameContext = $current !== null
            && $current['location_id'] === $location->id
            && $current['class_pass_plan_id'] === $classPassPlan->id;

        $this->session->put(self::SessionKey, [
            'account_id' => $account->id,
            'account_slug' => $account->slug,
            'location_id' => $location->id,
            'location_slug' => $location->slug,
            'class_pass_plan_id' => $classPassPlan->id,
            'class_pass_plan_slug' => $classPassPlan->slug,
            'purchase_id' => $sameContext ? ($current['purchase_id'] ?? null) : null,
            'expires_at' => now()->addMinutes(self::LifetimeMinutes)->timestamp,
        ]);
    }

    public function urlFor(Account $account): ?string
    {
        $data = $this->dataFor($account);

        if (! $data) {
            return null;
        }

        return route('public.class-pass-plans.checkout', [
            $data['account_slug'],
            $data['location_slug'],
            $data['class_pass_plan_slug'],
        ]);
    }

    public function rememberPurchase(
        Account $account,
        Location $location,
        ClassPassPlan $classPassPlan,
        CustomerPurchase $purchase,
    ): void {
        abort_unless(
            $purchase->account_id === $account->id
            && $purchase->location_id === $location->id
            && $purchase->class_pass_plan_id === $classPassPlan->id,
            404,
        );

        $this->remember($account, $location, $classPassPlan);
        $data = $this->dataFor($account);
        abort_unless($data, 404);
        $data['purchase_id'] = $purchase->id;
        $this->session->put(self::SessionKey, $data);
    }

    public function purchaseFor(
        Account $account,
        Location $location,
        ClassPassPlan $classPassPlan,
        ?Customer $customer,
    ): ?CustomerPurchase {
        $data = $this->dataFor($account);
        $purchaseId = $data['purchase_id'] ?? null;

        if (! $customer || ! is_int($purchaseId)) {
            return null;
        }

        $purchase = CustomerPurchase::query()
            ->whereBelongsTo($account)
            ->whereBelongsTo($customer)
            ->whereBelongsTo($location)
            ->whereBelongsTo($classPassPlan)
            ->with('customerClassPass')
            ->find($purchaseId);

        if (! $purchase) {
            $this->forgetPurchase();
        }

        return $purchase;
    }

    public function forgetPurchase(): void
    {
        $data = $this->session->get(self::SessionKey);

        if (! is_array($data)) {
            return;
        }

        $data['purchase_id'] = null;
        $this->session->put(self::SessionKey, $data);
    }

    public function forget(): void
    {
        $this->session->forget(self::SessionKey);
    }

    /**
     * @return array{account_id: int, account_slug: string, location_id: int, location_slug: string, class_pass_plan_id: int, class_pass_plan_slug: string, purchase_id: int|null, expires_at: int}|null
     */
    private function dataFor(Account $account): ?array
    {
        $data = $this->session->get(self::SessionKey);

        if (! is_array($data)
            || ($data['account_id'] ?? null) !== $account->id
            || ! is_int($data['expires_at'] ?? null)
            || $data['expires_at'] < now()->timestamp) {
            $this->forget();

            return null;
        }

        return $data;
    }
}

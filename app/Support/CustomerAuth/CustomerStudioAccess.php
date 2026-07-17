<?php

namespace App\Support\CustomerAuth;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CustomerStudioAccess
{
    /**
     * @return Collection<int, Customer>
     */
    public function matchingCustomersFor(Customer $customer): Collection
    {
        $identityConditions = $this->identityConditions($customer);

        return Customer::query()
            ->with('account:id,name,slug,status,logo_path,studio_slogan,default_language')
            ->where(function (Builder $query) use ($customer, $identityConditions): void {
                $query->whereKey($customer->getKey());

                foreach ($identityConditions as $condition) {
                    $query->orWhere($condition['column'], $condition['value']);
                }
            })
            ->whereHas('account', fn (Builder $query): Builder => $query->publiclyDiscoverable())
            ->get()
            ->sortBy(fn (Customer $studioCustomer): string => $studioCustomer->account?->name ?? '')
            ->values();
    }

    public function destinationFor(Customer $customer): ?string
    {
        $studioCustomers = $this->matchingCustomersFor($customer);

        if ($studioCustomers->isEmpty()) {
            return null;
        }

        if ($studioCustomers->count() > 1) {
            return route('customer.studios.index');
        }

        return $this->destinationForCustomer($studioCustomers->first());
    }

    public function destinationForCustomer(Customer $customer): string
    {
        $account = $customer->account;

        abort_unless($account, 404);

        if (! $customer->profileIsComplete()) {
            return route('customer.profile.complete', $account->slug);
        }

        return route('customer.dashboard', $account->slug);
    }

    public function matchingCustomerForAccount(Customer $customer, int $accountId): ?Customer
    {
        return $this->matchingCustomersFor($customer)
            ->first(fn (Customer $studioCustomer): bool => (int) $studioCustomer->account_id === $accountId);
    }

    public function canSwitch(Customer $currentCustomer, Customer $targetCustomer): bool
    {
        return $this->matchingCustomersFor($currentCustomer)
            ->contains(fn (Customer $studioCustomer): bool => $studioCustomer->is($targetCustomer));
    }

    /**
     * @return array<int, array{column: string, value: string}>
     */
    private function identityConditions(Customer $customer): array
    {
        $conditions = [];

        if (filled($customer->google_id)) {
            $conditions[] = ['column' => 'google_id', 'value' => (string) $customer->google_id];
        }

        if (filled($customer->phone) && filled($customer->phone_verified_at)) {
            $conditions[] = ['column' => 'phone', 'value' => (string) $customer->phone];
        }

        if (filled($customer->email) && filled($customer->email_verified_at)) {
            $conditions[] = ['column' => 'email', 'value' => (string) $customer->email];
        }

        return $conditions;
    }
}

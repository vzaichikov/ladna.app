<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\Customer;
use App\Support\PhoneNumberNormalizer;

class ResolveQuickBookingCustomer
{
    public function __construct(private readonly PhoneNumberNormalizer $phoneNumberNormalizer) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(Account $account, array $validated): Customer
    {
        $customerId = (int) ($validated['customer_id'] ?? 0);

        if ($customerId > 0) {
            return $account->customers()->whereKey($customerId)->firstOrFail();
        }

        $phone = $this->phoneNumberNormalizer->normalize(
            (string) ($validated['customer_phone'] ?? ''),
            $account->country_code,
        );
        $name = trim((string) ($validated['customer_name'] ?? ''));

        $customer = $phone
            ? $account->customers()->where('phone', $phone)->first()
            : null;

        if ($customer) {
            if (blank($customer->name) && $name !== '') {
                $customer->forceFill(['name' => $name])->save();
            }

            return $customer;
        }

        return $account->customers()->create([
            'name' => $name,
            'phone' => $phone,
            'default_language' => $account->default_language,
        ]);
    }
}

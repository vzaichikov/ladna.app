<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

class LinkGoogleCustomerByVerifiedPhone
{
    /**
     * @param  array{customer_id?: int|null, google_id: string, email?: string|null, email_verified?: bool, name?: string|null}  $googleData
     */
    public function execute(Account $account, array $googleData, string $phone, ?int $matchedCustomerId = null): Customer
    {
        return DB::transaction(function () use ($account, $googleData, $phone, $matchedCustomerId): Customer {
            $currentCustomer = $matchedCustomerId
                ? $account->customers()->whereKey($matchedCustomerId)->lockForUpdate()->first()
                : null;

            $googleCustomer = $account->customers()
                ->where('google_id', $googleData['google_id'])
                ->lockForUpdate()
                ->first();

            $phoneCustomer = $account->customers()
                ->where('phone', $phone)
                ->lockForUpdate()
                ->first();

            $targetCustomer = $phoneCustomer ?? $currentCustomer ?? $googleCustomer;

            if (! $targetCustomer) {
                return $account->customers()->create([
                    'name' => $this->stringOrNull($googleData['name'] ?? null),
                    'email' => $this->stringOrNull($googleData['email'] ?? null),
                    'email_verified_at' => ($googleData['email_verified'] ?? false) ? now() : null,
                    'phone' => $phone,
                    'phone_verified_at' => now(),
                    'google_id' => $googleData['google_id'],
                    'default_language' => $account->default_language,
                ]);
            }

            if ($googleCustomer && ! $googleCustomer->is($targetCustomer)) {
                $googleCustomer->forceFill(['google_id' => null])->save();
            }

            $updates = [
                'google_id' => $googleData['google_id'],
                'phone' => $targetCustomer->phone ?: $phone,
                'phone_verified_at' => $targetCustomer->phone_verified_at ?: now(),
            ];

            $email = $this->stringOrNull($googleData['email'] ?? null);
            $targetEmail = $targetCustomer->email;

            if (blank($targetEmail) && $email && $this->emailIsAvailable($account, $targetCustomer, $email)) {
                $updates['email'] = $email;
                $targetEmail = $email;
            }

            if (($googleData['email_verified'] ?? false) && $targetEmail === $email && blank($targetCustomer->email_verified_at)) {
                $updates['email_verified_at'] = now();
            }

            $name = $this->stringOrNull($googleData['name'] ?? null);

            if (blank($targetCustomer->name) && $name) {
                $updates['name'] = $name;
            }

            $targetCustomer->forceFill($updates)->save();

            return $targetCustomer;
        });
    }

    private function emailIsAvailable(Account $account, Customer $targetCustomer, string $email): bool
    {
        return ! $account->customers()
            ->where('email', $email)
            ->whereKeyNot($targetCustomer->getKey())
            ->exists();
    }

    private function stringOrNull(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}

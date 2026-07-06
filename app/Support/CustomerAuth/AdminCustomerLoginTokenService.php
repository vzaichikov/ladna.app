<?php

namespace App\Support\CustomerAuth;

use App\Models\Account;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AdminCustomerLoginTokenService
{
    private const TokenTtlMinutes = 5;

    public function issueUrl(Account $account, Customer $customer, User $issuer): string
    {
        if ((int) $customer->account_id !== (int) $account->id) {
            throw new InvalidArgumentException('Customer does not belong to the selected account.');
        }

        $token = Str::random(64);
        $expiresAt = now()->addMinutes(self::TokenTtlMinutes);

        Cache::put($this->cacheKey($token), [
            'account_id' => (int) $account->id,
            'customer_id' => (int) $customer->id,
            'issued_by_user_id' => (int) $issuer->id,
        ], $expiresAt);

        return URL::temporarySignedRoute('customer.admin-login.consume', $expiresAt, [
            'accountSlug' => $account->slug,
            'token' => $token,
        ]);
    }

    public function consume(Account $account, string $token): ?Customer
    {
        if (preg_match('/^[A-Za-z0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $payload = Cache::pull($this->cacheKey($token));

        if (! is_array($payload)) {
            return null;
        }

        $accountId = filter_var($payload['account_id'] ?? null, FILTER_VALIDATE_INT);
        $customerId = filter_var($payload['customer_id'] ?? null, FILTER_VALIDATE_INT);

        if ($accountId !== (int) $account->id || ! is_int($customerId)) {
            return null;
        }

        return $account->customers()->whereKey($customerId)->first();
    }

    private function cacheKey(string $token): string
    {
        return 'customer-admin-login:'.hash('sha256', $token);
    }
}

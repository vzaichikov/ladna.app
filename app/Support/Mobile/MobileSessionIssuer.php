<?php

namespace App\Support\Mobile;

use App\Models\Account;
use App\Models\Customer;
use App\Models\MobileSession;
use App\Models\User;
use Illuminate\Support\Str;

class MobileSessionIssuer
{
    public function issueForStaff(Account $account, User $user, string $role, ?string $deviceName = null, ?string $platform = null): MobileSession
    {
        return $this->issue($account, MobileSession::GuardStaff, $role, $deviceName, $platform, user: $user);
    }

    public function issueForCustomer(Account $account, Customer $customer, ?string $deviceName = null, ?string $platform = null): MobileSession
    {
        return $this->issue($account, MobileSession::GuardCustomer, 'customer', $deviceName, $platform, customer: $customer);
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return array{session: MobileSession, token: string}
     */
    public function withToken(MobileSession $session): array
    {
        return [
            'session' => $session,
            'token' => (string) $session->getAttribute('plain_token'),
        ];
    }

    private function issue(
        Account $account,
        string $guard,
        string $role,
        ?string $deviceName = null,
        ?string $platform = null,
        ?User $user = null,
        ?Customer $customer = null,
    ): MobileSession {
        do {
            $token = 'ladna_mobile_'.Str::random(80);
            $tokenHash = $this->hash($token);
        } while (MobileSession::where('token_hash', $tokenHash)->exists());

        $session = MobileSession::create([
            'account_id' => $account->id,
            'user_id' => $user?->id,
            'customer_id' => $customer?->id,
            'guard' => $guard,
            'role' => $role,
            'token_hash' => $tokenHash,
            'last_four' => substr($token, -4),
            'device_name' => $deviceName,
            'platform' => $platform,
            'expires_at' => now()->addDays((int) config('mobile.sessions.days')),
            'last_used_at' => now(),
        ]);
        $session->setAttribute('plain_token', $token);

        return $session;
    }
}

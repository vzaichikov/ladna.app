<?php

namespace App\Support\FestivalAuth;

use App\Enums\FestivalPortalRole;
use App\Models\Account;
use App\Models\FestivalPortalUser;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AdminFestivalLoginTokenService
{
    private const TokenTtlMinutes = 5;

    public function issueUrl(Account $account, FestivalPortalUser $portalUser, User $issuer): string
    {
        if ((int) $portalUser->account_id !== (int) $account->id
            || $portalUser->role !== FestivalPortalRole::Registrant
            || ! $portalUser->is_active) {
            throw new InvalidArgumentException('An active Festival registrant must belong to the selected account.');
        }

        $token = Str::random(64);
        $expiresAt = now()->addMinutes(self::TokenTtlMinutes);
        Cache::put($this->cacheKey($token), [
            'account_id' => (int) $account->id,
            'festival_portal_user_id' => (int) $portalUser->id,
            'issued_by_user_id' => (int) $issuer->id,
        ], $expiresAt);

        return URL::temporarySignedRoute('festival.admin-login.consume', $expiresAt, [
            'accountSlug' => $account->slug,
            'token' => $token,
        ]);
    }

    public function consume(Account $account, string $token): ?FestivalPortalUser
    {
        if (preg_match('/^[A-Za-z0-9]{64}$/', $token) !== 1) {
            return null;
        }

        try {
            $payload = Cache::lock($this->lockKey($token), 10)->block(
                2,
                fn (): mixed => Cache::pull($this->cacheKey($token)),
            );
        } catch (LockTimeoutException) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        $accountId = filter_var($payload['account_id'] ?? null, FILTER_VALIDATE_INT);
        $portalUserId = filter_var($payload['festival_portal_user_id'] ?? null, FILTER_VALIDATE_INT);

        if ($accountId !== (int) $account->id || ! is_int($portalUserId)) {
            return null;
        }

        return $account->festivalPortalUsers()->active()->forRole(FestivalPortalRole::Registrant)->whereKey($portalUserId)->first();
    }

    private function cacheKey(string $token): string
    {
        return 'festival-admin-login:'.hash('sha256', $token);
    }

    private function lockKey(string $token): string
    {
        return 'festival-admin-login-lock:'.hash('sha256', $token);
    }
}

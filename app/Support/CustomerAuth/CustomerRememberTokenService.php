<?php

namespace App\Support\CustomerAuth;

use App\Models\Customer;
use App\Models\CustomerRememberToken;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class CustomerRememberTokenService
{
    private const CookieName = 'ladna_customer_remember';

    public function issue(Customer $customer): void
    {
        $this->deleteExpiredTokens();

        $selector = bin2hex(random_bytes(16));
        $token = Str::random(64);

        $customer->rememberTokens()->create([
            'selector' => $selector,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $this->newExpiry(),
        ]);

        $this->queueRememberCookie($selector.'|'.$token);
    }

    public function authenticateFromCookie(Request $request): ?Customer
    {
        $guardCustomer = $this->guard()->user();

        $value = $request->cookie(self::CookieName);

        if (! is_string($value) || ! str_contains($value, '|')) {
            return $guardCustomer instanceof Customer ? $guardCustomer : null;
        }

        [$selector, $token] = explode('|', $value, 2);

        $rememberToken = CustomerRememberToken::query()
            ->with('customer')
            ->where('selector', $selector)
            ->where('expires_at', '>', now())
            ->first();

        if (! $rememberToken || ! hash_equals($rememberToken->token_hash, hash('sha256', $token))) {
            Cookie::queue(Cookie::forget(self::CookieName));

            return $guardCustomer instanceof Customer ? $guardCustomer : null;
        }

        if ($guardCustomer instanceof Customer && $guardCustomer->getKey() !== $rememberToken->customer_id) {
            Cookie::queue(Cookie::forget(self::CookieName));

            return $guardCustomer;
        }

        $rememberToken->forceFill([
            'expires_at' => $this->newExpiry(),
            'last_used_at' => now(),
        ])->save();
        $this->queueRememberCookie($value);

        if (! ($guardCustomer instanceof Customer)) {
            $this->guard()->login($rememberToken->customer);
        }

        return $rememberToken->customer;
    }

    public function forget(?Request $request = null): void
    {
        if ($request) {
            $value = $request->cookie(self::CookieName);

            if (is_string($value) && str_contains($value, '|')) {
                [$selector] = explode('|', $value, 2);
                CustomerRememberToken::query()->where('selector', $selector)->delete();
            }
        }

        Cookie::queue(Cookie::forget(self::CookieName));
    }

    private function deleteExpiredTokens(): void
    {
        CustomerRememberToken::query()
            ->where('expires_at', '<=', now())
            ->delete();
    }

    private function newExpiry(): Carbon
    {
        return now()->addDays((int) config('customer_auth.remember_days'));
    }

    private function queueRememberCookie(string $value): void
    {
        Cookie::queue(cookie(
            self::CookieName,
            $value,
            $this->cookieMinutes(),
            null,
            null,
            config('session.secure'),
            true,
            false,
            config('session.same_site'),
        ));
    }

    private function cookieMinutes(): int
    {
        return (int) config('customer_auth.remember_days') * 24 * 60;
    }

    private function guard(): Guard
    {
        return Auth::guard('customer');
    }
}

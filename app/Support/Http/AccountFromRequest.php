<?php

namespace App\Support\Http;

use App\Models\Account;
use App\Models\User;
use Illuminate\Http\Request;

class AccountFromRequest
{
    public function resolve(Request $request): ?Account
    {
        $routeAccount = $request->route('account');

        if ($routeAccount instanceof Account) {
            return $routeAccount;
        }

        $attributeAccount = $request->attributes->get('account');

        if ($attributeAccount instanceof Account) {
            return $attributeAccount;
        }

        $accountSlug = $request->route('accountSlug');

        if (! is_string($accountSlug) || $accountSlug === '') {
            $accountSlug = $request->input('account_slug');
        }

        if (! is_string($accountSlug) || $accountSlug === '' || mb_strlen($accountSlug) > 255) {
            return $this->singleReadOnlyDemoFor($request);
        }

        return Account::query()
            ->where('slug', $accountSlug)
            ->first();
    }

    private function singleReadOnlyDemoFor(Request $request): ?Account
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isPlatformAdmin()) {
            return null;
        }

        $accounts = $user->accounts()->limit(2)->get();

        if ($accounts->count() !== 1) {
            return null;
        }

        $account = $accounts->first();

        return $account?->isReadOnlyDemo() ? $account : null;
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Customer;
use App\Models\User;
use App\Support\CustomerAuth\AdminCustomerLoginTokenService;
use App\Support\CustomerAuth\CustomerRememberTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;

class AdminCustomerLoginController extends Controller
{
    public function store(
        Request $request,
        Account $account,
        Customer $customer,
        AdminCustomerLoginTokenService $tokens,
    ): RedirectResponse {
        $this->authorize('view', $account);

        $user = $request->user();

        abort_unless($user instanceof User && $account->isOwnedBy($user), 403);
        abort_unless((int) $customer->account_id === (int) $account->id, 404);

        return redirect()->away($tokens->issueUrl($account, $customer, $user));
    }

    public function consume(
        Request $request,
        string $accountSlug,
        string $token,
        AdminCustomerLoginTokenService $tokens,
        CustomerRememberTokenService $rememberTokens,
    ): RedirectResponse {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $customer = $tokens->consume($account, $token);

        abort_unless($customer instanceof Customer, 404);

        if (! $request->session()->has('locale')) {
            App::setLocale($account->default_language);
        }

        $rememberTokens->forget($request);
        Auth::guard('customer')->logout();
        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        if ($customer->profileIsComplete()) {
            return redirect()->route('customer.dashboard', $account->slug);
        }

        return redirect()->route('customer.profile.complete', $account->slug);
    }
}

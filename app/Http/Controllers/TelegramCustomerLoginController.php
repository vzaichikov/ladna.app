<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Customer;
use App\Support\CustomerAuth\CustomerRememberTokenService;
use App\Support\CustomerAuth\TelegramCustomerLoginTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;

class TelegramCustomerLoginController extends Controller
{
    public function __invoke(
        Request $request,
        string $accountSlug,
        string $token,
        TelegramCustomerLoginTokenService $tokens,
        CustomerRememberTokenService $rememberTokens,
    ): RedirectResponse {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();

        abort_if($account->isReadOnlyDemo(), 404);

        $customer = $tokens->consume($account, $token);

        abort_unless($customer instanceof Customer, 404);

        if (! $request->session()->has('locale')) {
            App::setLocale($customer->default_language ?: $account->default_language);
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

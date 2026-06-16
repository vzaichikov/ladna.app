<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

class CustomerAuthController extends Controller
{
    public function create(): View
    {
        return view('customer-auth.login');
    }

    public function studioLogin(string $accountSlug): View
    {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $this->setAccountLocale($account);

        return view('customer-auth.login', [
            'account' => $account,
        ]);
    }

    public function studioDashboard(string $accountSlug): View
    {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $this->setAccountLocale($account);

        return view('customer-auth.dashboard', [
            'account' => $account,
        ]);
    }

    private function setAccountLocale(Account $account): void
    {
        if (! session()->has('locale')) {
            App::setLocale($account->default_language);
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

class PublicStudioRulesController extends Controller
{
    public function __invoke(string $accountSlug): View
    {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $this->setAccountLocale($account);

        return view('public.studio-rules', [
            'account' => $account,
        ]);
    }

    private function setAccountLocale(Account $account): void
    {
        if (! session()->has('locale')) {
            App::setLocale($account->default_language);
            Carbon::setLocale($account->default_language);
        }
    }
}

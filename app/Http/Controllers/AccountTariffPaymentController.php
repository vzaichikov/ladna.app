<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountTariffPaymentController extends Controller
{
    public function __invoke(Request $request, Account $account): View
    {
        $this->authorize('view', $account);
        abort_unless($account->isOwnedBy($request->user()), 403);

        $account->loadMissing('subscription.plan');

        return view('accounts.tariff-payments', [
            'account' => $account,
            'subscription' => $account->subscription,
        ]);
    }
}

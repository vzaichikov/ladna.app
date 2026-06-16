<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\View\View;

class PlatformController extends Controller
{
    public function __invoke(): View
    {
        return view('platform.index', [
            'accountsCount' => Account::count(),
            'activeAccountsCount' => Account::active()->count(),
            'recentAccounts' => Account::with('subscription.plan')
                ->latest()
                ->limit(8)
                ->get(),
        ]);
    }
}

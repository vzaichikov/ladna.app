<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Http\RedirectResponse;

class StudioSettingsController extends Controller
{
    public function index(Account $account): RedirectResponse
    {
        $this->authorize('update', $account);

        return redirect()->route('dashboard.accounts.trainer-types.index', $account);
    }
}

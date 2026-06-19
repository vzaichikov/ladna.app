<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\View\View;

class StudioSettingsController extends Controller
{
    public function index(Account $account): View
    {
        $this->authorize('update', $account);
        $account->ensureDefaultTrainerType();

        return view('studio-settings.index', [
            'account' => $account,
            'activeTab' => 'trainer-types',
            'iconOptions' => config('icons.trainer_types'),
            'trainerTypes' => $account->trainerTypes()->ordered()->get(),
        ]);
    }
}

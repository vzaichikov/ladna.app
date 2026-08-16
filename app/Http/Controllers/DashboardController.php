<?php

namespace App\Http\Controllers;

use App\Enums\AccountRole;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $accounts = $request->user()
            ->accounts()
            ->withCount('locations')
            ->orderBy('name')
            ->get();

        if (! $request->user()->isPlatformAdmin() && $accounts->count() === 1) {
            return redirect()->to($this->accountDestination($accounts->first()));
        }

        return view('dashboard.index', [
            'accounts' => $accounts,
            'accountDestinations' => $accounts->mapWithKeys(fn (Account $account): array => [
                $account->id => $this->accountDestination($account),
            ]),
        ]);
    }

    private function accountDestination(Account $account): string
    {
        if ($account->pivot?->role === AccountRole::EventFestivalStaff) {
            return route('dashboard.accounts.events.index', $account);
        }

        return route('dashboard.accounts.show', $account);
    }
}

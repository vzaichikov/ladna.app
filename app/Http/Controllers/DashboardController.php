<?php

namespace App\Http\Controllers;

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
            return redirect()->route('dashboard.accounts.show', $accounts->first());
        }

        return view('dashboard.index', [
            'accounts' => $accounts,
        ]);
    }
}

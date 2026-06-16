<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $accounts = $request->user()
            ->accounts()
            ->withCount('locations')
            ->orderBy('name')
            ->get();

        return view('dashboard.index', [
            'accounts' => $accounts,
        ]);
    }
}

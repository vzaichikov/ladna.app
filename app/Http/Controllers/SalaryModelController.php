<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalaryModelController extends Controller
{
    public function __invoke(Request $request, Account $account): View
    {
        abort_unless($request->user()?->can('manageStudioCashflow', $account), 403);

        return view('reports.salary-models', [
            'account' => $account,
        ]);
    }
}

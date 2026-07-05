<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\Reports\PeopleCounterReportData;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PeopleCounterReportController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Account $account, PeopleCounterReportData $reportData): View
    {
        $this->authorize('viewReports', $account);
        abort_unless($account->allowsRtspCameras() && $account->peopleCounterEnabled(), 404);

        return view('reports.people-counter', [
            'account' => $account,
            'classes' => $reportData->forAccount($account, 25),
        ]);
    }
}

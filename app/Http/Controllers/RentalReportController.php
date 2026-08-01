<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinancePeriodRequest;
use App\Models\Account;
use App\Support\Finance\RentalReportData;
use Illuminate\View\View;

class RentalReportController extends Controller
{
    public function __invoke(
        FinancePeriodRequest $request,
        Account $account,
        RentalReportData $reportData,
    ): View {
        [$startsAt, $endsAt] = $request->databaseRange();

        return view('accounts.reports.finance.rentals', [
            'account' => $account,
            'filters' => $request->filters(),
            'locations' => $account->locations()->orderBy('name')->get(),
            'epoch' => $request->financeEpoch(),
            'report' => $reportData->forAccount($account, $request->filters(), $startsAt, $endsAt),
        ]);
    }
}

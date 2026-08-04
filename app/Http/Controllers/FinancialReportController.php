<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinancePeriodRequest;
use App\Models\Account;
use App\Support\Finance\FinanceReportData;
use Illuminate\View\View;

class FinancialReportController extends Controller
{
    public function __invoke(
        FinancePeriodRequest $request,
        Account $account,
        FinanceReportData $reportData,
    ): View {
        [$startsAt, $endsAt] = $request->databaseRange();
        $epoch = $request->financeEpoch();
        $filters = $request->filters();
        $locations = $account->locations()->orderBy('name')->get();
        $report = $reportData->forAccount(
            $account,
            $filters,
            $startsAt,
            $endsAt,
            $epoch,
        );

        return view('accounts.reports.finance.financial', [
            'account' => $account,
            'filters' => $filters,
            'locations' => $locations,
            'epoch' => $epoch,
            'report' => $report,
            'reportView' => $request->reportView(),
            'locationComparison' => $request->reportView() === 'compare'
                ? $reportData->locationComparison($report, $locations)
                : null,
        ]);
    }
}

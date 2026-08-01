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

        return view('accounts.reports.finance.financial', [
            'account' => $account,
            'filters' => $request->filters(),
            'locations' => $account->locations()->orderBy('name')->get(),
            'epoch' => $epoch,
            'report' => $reportData->forAccount(
                $account,
                $request->filters(),
                $startsAt,
                $endsAt,
                $epoch,
            ),
        ]);
    }
}

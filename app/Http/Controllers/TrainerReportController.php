<?php

namespace App\Http\Controllers;

use App\Enums\ClassBookingStatus;
use App\Http\Requests\TrainerReportRequest;
use App\Models\Account;
use App\Support\Reports\TrainerReportData;
use Illuminate\View\View;

class TrainerReportController extends Controller
{
    public function __invoke(TrainerReportRequest $request, Account $account, TrainerReportData $reportData): View
    {
        $filters = $request->filters();
        $canManageStudioCashflow = $request->user()?->can('manageStudioCashflow', $account) ?? false;
        $rows = $reportData->forAccount($account, $filters);

        return view('reports.trainers', [
            'account' => $account,
            'filters' => $filters,
            'locations' => $account->locations()->orderBy('name')->get(['id', 'name']),
            'statuses' => ClassBookingStatus::cases(),
            'rows' => $rows,
            'totals' => $reportData->totals($rows),
            'canManageStudioCashflow' => $canManageStudioCashflow,
        ]);
    }
}

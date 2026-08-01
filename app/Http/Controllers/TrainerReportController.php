<?php

namespace App\Http\Controllers;

use App\Enums\ClassBookingStatus;
use App\Http\Requests\TrainerReportRequest;
use App\Models\Account;
use App\Support\Reports\TrainerReportData;
use App\Support\Salary\TrainerSalaryCalculator;
use Illuminate\View\View;

class TrainerReportController extends Controller
{
    public function __invoke(
        TrainerReportRequest $request,
        Account $account,
        TrainerReportData $reportData,
        TrainerSalaryCalculator $salaryCalculator,
    ): View {
        $filters = $request->filters();
        $canManageStudioCashflow = $request->user()?->can('manageStudioPayroll', $account) ?? false;
        $rows = $reportData->forAccount($account, $filters);
        $salaryReport = $canManageStudioCashflow
            ? $salaryCalculator->forAccount($account, $filters)
            : null;
        $rows = $rows->map(function (array $row) use ($salaryReport): array {
            if (! $salaryReport) {
                return $row;
            }

            return [
                ...$row,
                'salary' => $salaryReport['trainers']->get($row['trainer']->id),
            ];
        });

        return view('reports.trainers', [
            'account' => $account,
            'filters' => $filters,
            'locations' => $account->locations()->orderBy('name')->get(['id', 'name']),
            'statuses' => ClassBookingStatus::cases(),
            'rows' => $rows,
            'totals' => $reportData->totals($rows),
            'canManageStudioCashflow' => $canManageStudioCashflow,
            'salaryReport' => $salaryReport,
        ]);
    }
}

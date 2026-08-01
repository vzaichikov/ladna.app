<?php

namespace App\Http\Controllers;

use App\Http\Requests\TrainerReportRequest;
use App\Models\Account;
use App\Models\Trainer;
use App\Support\Salary\TrainerSalaryCalculator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class TrainerSalaryReportController extends Controller
{
    public function __invoke(
        TrainerReportRequest $request,
        Account $account,
        Trainer $trainer,
        TrainerSalaryCalculator $calculator,
    ): View {
        abort_unless($trainer->account_id === $account->id, 404);
        abort_unless($request->user()?->can('manageStudioPayroll', $account), 403);
        $filters = $request->filters();
        $salary = $calculator->forTrainer($account, $trainer, $filters);
        $entries = $salary['entries'];
        $perPage = 50;
        $page = max(1, $request->integer('page', 1));
        $paginator = new LengthAwarePaginator(
            $entries->forPage($page, $perPage)->values(),
            $entries->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('reports.trainer-salary', [
            'account' => $account,
            'trainer' => $trainer,
            'filters' => $filters,
            'salary' => $salary,
            'entries' => $paginator,
        ]);
    }
}

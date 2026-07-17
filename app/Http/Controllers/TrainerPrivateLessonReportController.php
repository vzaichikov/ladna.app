<?php

namespace App\Http\Controllers;

use App\Http\Requests\TrainerReportRequest;
use App\Models\Account;
use App\Models\Trainer;
use App\Support\Reports\TrainerReportData;
use Illuminate\View\View;

class TrainerPrivateLessonReportController extends Controller
{
    public function __invoke(
        TrainerReportRequest $request,
        Account $account,
        Trainer $trainer,
        TrainerReportData $reportData,
    ): View {
        abort_unless($trainer->account_id === $account->id, 404);

        $canManageStudioCashflow = $request->user()?->can('manageStudioCashflow', $account) ?? false;

        return view('reports.trainer-private-lessons', [
            'account' => $account,
            'trainer' => $trainer,
            'privateLessons' => $reportData->privateLessons(
                $account,
                $trainer,
                $request->filters(),
                $canManageStudioCashflow,
            ),
            'canManageStudioCashflow' => $canManageStudioCashflow,
        ]);
    }
}

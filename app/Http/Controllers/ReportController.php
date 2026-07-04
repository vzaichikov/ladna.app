<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Account $account): View
    {
        $this->authorize('viewReports', $account);
        $reports = [
            [
                'title' => __('app.trainer_report_title'),
                'copy' => __('app.trainer_report_card_copy'),
                'icon' => 'trainers',
                'href' => route('dashboard.accounts.reports.trainers', $account),
            ],
        ];

        return view('reports.index', [
            'account' => $account,
            'reports' => $reports,
        ]);
    }
}

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

        if ($account->allowsRtspCameras() && $account->peopleCounterEnabled()) {
            $reports[] = [
                'title' => __('app.people_counter_report_title'),
                'copy' => __('app.people_counter_report_card_copy'),
                'icon' => 'video',
                'href' => route('dashboard.accounts.reports.people-counter', $account),
            ];
            $reports[] = [
                'title' => __('app.unknown_presence_report_title'),
                'copy' => __('app.unknown_presence_report_card_copy'),
                'icon' => 'video',
                'href' => route('dashboard.accounts.reports.unknown-presence', $account),
            ];
        }

        return view('reports.index', [
            'account' => $account,
            'reports' => $reports,
        ]);
    }
}

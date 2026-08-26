<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Account $account): View
    {
        $this->authorize('viewReportIndex', $account);
        $user = request()->user();
        $reportGroups = [];

        if ($user?->can('viewStudioFinancialReports', $account)) {
            $reportGroups[] = [
                'title' => __('app.financial_reports'),
                'copy' => __('app.financial_reports_copy'),
                'reports' => [
                    [
                        'title' => __('app.financial_report_title'),
                        'copy' => __('app.financial_report_copy'),
                        'icon' => 'reports',
                        'href' => route('dashboard.accounts.reports.financial', $account),
                    ],
                    [
                        'title' => __('app.earnings_report_title'),
                        'copy' => __('app.earnings_report_copy'),
                        'icon' => 'reports',
                        'href' => route('dashboard.accounts.reports.earnings', $account),
                    ],
                    [
                        'title' => __('app.rental_report_title'),
                        'copy' => __('app.rental_report_copy'),
                        'icon' => 'reports',
                        'href' => route('dashboard.accounts.reports.rentals', $account),
                    ],
                ],
            ];
        }

        if ($user?->can('viewReports', $account)) {
            $reports = [
                [
                    'title' => __('app.trainer_report_title'),
                    'copy' => __('app.trainer_report_card_copy'),
                    'icon' => 'trainers',
                    'href' => route('dashboard.accounts.reports.trainers', $account),
                ],
                [
                    'title' => __('app.unreserved_class_bookings_report_title'),
                    'copy' => __('app.unreserved_class_bookings_report_card_copy'),
                    'icon' => 'calendar',
                    'href' => route('dashboard.accounts.reports.unreserved-class-bookings', $account),
                ],
                [
                    'title' => __('app.unpaid_class_booking_payments_report_title'),
                    'copy' => __('app.unpaid_class_booking_payments_report_card_copy'),
                    'icon' => 'payments',
                    'href' => route('dashboard.accounts.reports.unpaid-class-payments', $account),
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

            $reportGroups[] = [
                'title' => __('app.operational_reports'),
                'copy' => __('app.operational_reports_copy'),
                'reports' => $reports,
            ];
        }

        return view('reports.index', [
            'account' => $account,
            'reportGroups' => $reportGroups,
        ]);
    }
}

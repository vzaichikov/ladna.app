<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\Reports\UnpaidClassBookingPaymentsReport;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UnpaidClassPaymentReportController extends Controller
{
    public function __invoke(Request $request, Account $account, UnpaidClassBookingPaymentsReport $report): View
    {
        $this->authorize('viewReports', $account);

        return view('reports.unpaid-class-payments', [
            'account' => $account,
            'bookings' => $report->paginateForAccount($account),
            'canManageBookingPayments' => $request->user()?->can('manageBookings', $account) ?? false,
        ]);
    }
}

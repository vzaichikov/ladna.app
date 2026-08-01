<?php

namespace App\Http\Controllers;

use App\Enums\CustomerPurchaseStatus;
use App\Http\Requests\CashflowFilterRequest;
use App\Models\Account;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseRefund;
use App\Models\StudioExpense;
use App\Support\Payments\AccountPaymentDashboardData;
use Illuminate\View\View;

class StudioCashController extends Controller
{
    public function index(
        CashflowFilterRequest $request,
        Account $account,
        AccountPaymentDashboardData $dashboardData,
    ): View {
        $filters = $request->filters();
        [$startsAt, $endsAt] = $request->databaseRange();

        return view('accounts.payments.index', [
            'account' => $account,
            ...$dashboardData->build($account, $filters, $startsAt, $endsAt, false),
            'filters' => $filters,
            'status' => $filters['status'],
            'provider' => $filters['provider'],
            'locationId' => $filters['location_id'],
            'locations' => $account->locations()->orderBy('name')->get(),
            'statuses' => [
                ...array_column(CustomerPurchaseStatus::cases(), 'value'),
                CustomerPurchaseRefund::StatusRecorded,
            ],
            'paymentMethods' => CustomerPurchase::paymentMethods(),
            'expensePaymentMethods' => StudioExpense::paymentMethods(),
            'expenseStatuses' => StudioExpense::statuses(),
            'fiscalizationEnabled' => false,
            'canManageStudioCashflow' => true,
            'financeSection' => 'cash',
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\CustomerPurchaseStatus;
use App\Http\Requests\AccountPaymentFilterRequest;
use App\Models\Account;
use App\Models\CustomerPurchase;
use App\Models\StudioExpense;
use App\Support\Fiscalization\FiscalizationAvailability;
use App\Support\Payments\AccountPaymentDashboardData;
use Illuminate\View\View;

class AccountPaymentController extends Controller
{
    public function index(
        AccountPaymentFilterRequest $request,
        Account $account,
        FiscalizationAvailability $fiscalization,
        AccountPaymentDashboardData $dashboardData,
    ): View {
        $filters = $request->filters();
        [$startsAt, $endsAt] = $request->databaseRange();
        $fiscalizationEnabled = $fiscalization->enabledForAccount($account);

        return view('accounts.payments.index', [
            'account' => $account,
            ...$dashboardData->build($account, $filters, $startsAt, $endsAt, $fiscalizationEnabled),
            'filters' => $filters,
            'status' => $filters['status'],
            'provider' => $filters['provider'],
            'locationId' => $filters['location_id'],
            'locations' => $account->locations()->orderBy('name')->get(),
            'statuses' => CustomerPurchaseStatus::cases(),
            'paymentMethods' => CustomerPurchase::paymentMethods(),
            'expensePaymentMethods' => StudioExpense::paymentMethods(),
            'expenseStatuses' => StudioExpense::statuses(),
            'fiscalizationEnabled' => $fiscalizationEnabled,
            'canManageStudioCashflow' => true,
        ]);
    }
}

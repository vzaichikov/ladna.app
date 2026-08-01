<?php

namespace App\Http\Controllers;

use App\Actions\CreateStudioExpense;
use App\Actions\VoidStudioExpense;
use App\Enums\CustomerPurchaseStatus;
use App\Http\Requests\CashflowFilterRequest;
use App\Http\Requests\StoreStudioExpenseRequest;
use App\Http\Requests\VoidStudioExpenseRequest;
use App\Models\Account;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseRefund;
use App\Models\StudioExpense;
use App\Support\Payments\AccountPaymentDashboardData;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class StudioExpenseController extends Controller
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
            'financeSection' => 'expenses',
        ]);
    }

    public function store(StoreStudioExpenseRequest $request, Account $account, CreateStudioExpense $createStudioExpense): RedirectResponse
    {
        $validated = $request->validated();
        $expenseCategory = $account->expenseCategories()->whereKey($validated['expense_category_id'])->firstOrFail();
        $expenseLocation = isset($validated['expense_location_id'])
            ? $account->locations()->whereKey($validated['expense_location_id'])->firstOrFail()
            : null;
        $cashLocation = isset($validated['cash_location_id'])
            ? $account->locations()->whereKey($validated['cash_location_id'])->firstOrFail()
            : null;

        $createStudioExpense->execute(
            $account,
            $expenseCategory,
            $expenseLocation,
            $validated['payment_method'],
            $request->amountCents(),
            $request->occurredAt(),
            $request->user(),
            $validated['reason'],
            $cashLocation,
            $validated['idempotency_key'],
        );

        return back()->with('status', __('app.studio_expense_created'));
    }

    public function void(VoidStudioExpenseRequest $request, Account $account, StudioExpense $studioExpense, VoidStudioExpense $voidStudioExpense): RedirectResponse
    {
        $voidStudioExpense->execute(
            $account,
            $studioExpense,
            $request->user(),
            $request->validated('reason'),
        );

        return back()->with('status', __('app.studio_expense_voided'));
    }
}

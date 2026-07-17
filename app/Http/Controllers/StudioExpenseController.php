<?php

namespace App\Http\Controllers;

use App\Actions\CreateStudioExpense;
use App\Actions\VoidStudioExpense;
use App\Http\Requests\StoreStudioExpenseRequest;
use App\Http\Requests\VoidStudioExpenseRequest;
use App\Models\Account;
use App\Models\StudioExpense;
use Illuminate\Http\RedirectResponse;

class StudioExpenseController extends Controller
{
    public function store(StoreStudioExpenseRequest $request, Account $account, CreateStudioExpense $createStudioExpense): RedirectResponse
    {
        $validated = $request->validated();
        $expenseCategory = $account->expenseCategories()->whereKey($validated['expense_category_id'])->firstOrFail();
        $location = isset($validated['location_id'])
            ? $account->locations()->whereKey($validated['location_id'])->firstOrFail()
            : null;

        $createStudioExpense->execute(
            $account,
            $expenseCategory,
            $location,
            $validated['payment_method'],
            $request->amountCents(),
            $request->occurredAt(),
            $request->user(),
            $validated['reason'],
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

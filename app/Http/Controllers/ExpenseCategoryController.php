<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExpenseCategoryRequest;
use App\Http\Requests\UpdateExpenseCategoryRequest;
use App\Http\Requests\UpdateExpenseCategoryStatusRequest;
use App\Models\Account;
use App\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class ExpenseCategoryController extends Controller
{
    public function store(StoreExpenseCategoryRequest $request, Account $account): RedirectResponse
    {
        $account->expenseCategories()->create([
            'name' => $request->validated('name'),
            'is_active' => true,
        ]);

        return back()->with('status', __('app.expense_category_created'));
    }

    public function update(UpdateExpenseCategoryRequest $request, Account $account, ExpenseCategory $expenseCategory): RedirectResponse
    {
        DB::transaction(function () use ($request, $account, $expenseCategory): void {
            ExpenseCategory::query()
                ->whereBelongsTo($account)
                ->whereKey($expenseCategory->id)
                ->lockForUpdate()
                ->firstOrFail()
                ->update(['name' => $request->validated('name')]);
        }, 5);

        return back()->with('status', __('app.expense_category_updated'));
    }

    public function updateStatus(UpdateExpenseCategoryStatusRequest $request, Account $account, ExpenseCategory $expenseCategory): RedirectResponse
    {
        DB::transaction(function () use ($request, $account, $expenseCategory): void {
            ExpenseCategory::query()
                ->whereBelongsTo($account)
                ->whereKey($expenseCategory->id)
                ->lockForUpdate()
                ->firstOrFail()
                ->update(['is_active' => $request->boolean('is_active')]);
        }, 5);

        return back()->with('status', __('app.expense_category_status_updated'));
    }
}

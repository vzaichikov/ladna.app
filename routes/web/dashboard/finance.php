<?php

use App\Http\Controllers\CustomerPurchaseCorrectionController;
use App\Http\Controllers\CustomerPurchaseRefundController;
use App\Http\Controllers\EarningsReportController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\FinancialReportController;
use App\Http\Controllers\PayrollRunController;
use App\Http\Controllers\PeopleCounterReportController;
use App\Http\Controllers\PeopleCounterScreenshotController;
use App\Http\Controllers\RentalReportController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SalaryModelController;
use App\Http\Controllers\StudioCashEntryController;
use App\Http\Controllers\StudioExpenseController;
use App\Http\Controllers\TrainerPrivateLessonReportController;
use App\Http\Controllers\TrainerReportController;
use App\Http\Controllers\TrainerSalaryAssignmentController;
use App\Http\Controllers\TrainerSalaryReportController;
use App\Http\Controllers\UnknownPresenceReportController;
use App\Http\Controllers\UnpaidClassPaymentReportController;
use Illuminate\Support\Facades\Route;

Route::post('accounts/{account}/cash-entries', [StudioCashEntryController::class, 'store'])
    ->name('accounts.cash-entries.store');
Route::post('accounts/{account}/expense-categories', [ExpenseCategoryController::class, 'store'])
    ->name('accounts.expense-categories.store');
Route::patch('accounts/{account}/expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'update'])
    ->scopeBindings()
    ->name('accounts.expense-categories.update');
Route::patch('accounts/{account}/expense-categories/{expenseCategory}/status', [ExpenseCategoryController::class, 'updateStatus'])
    ->scopeBindings()
    ->name('accounts.expense-categories.status');
Route::post('accounts/{account}/expenses', [StudioExpenseController::class, 'store'])
    ->name('accounts.expenses.store');
Route::patch('accounts/{account}/expenses/{studioExpense}/void', [StudioExpenseController::class, 'void'])
    ->scopeBindings()
    ->name('accounts.expenses.void');
Route::post('accounts/{account}/payments/{customerPurchase}/corrections', [CustomerPurchaseCorrectionController::class, 'store'])
    ->name('accounts.payments.corrections.store');
Route::post('accounts/{account}/payments/{customerPurchase}/refunds', [CustomerPurchaseRefundController::class, 'store'])
    ->scopeBindings()
    ->name('accounts.payments.refunds.store');
Route::get('accounts/{account}/reports', [ReportController::class, 'index'])
    ->name('accounts.reports.index');
Route::get('accounts/{account}/reports/financial', FinancialReportController::class)
    ->name('accounts.reports.financial');
Route::get('accounts/{account}/reports/earnings', EarningsReportController::class)
    ->name('accounts.reports.earnings');
Route::get('accounts/{account}/reports/rentals', RentalReportController::class)
    ->name('accounts.reports.rentals');
Route::get('accounts/{account}/payroll', [PayrollRunController::class, 'index'])
    ->name('accounts.payroll.index');
Route::patch('accounts/{account}/payroll/cadence', [PayrollRunController::class, 'updateCadence'])
    ->name('accounts.payroll.cadence.update');
Route::post('accounts/{account}/payroll/runs', [PayrollRunController::class, 'store'])
    ->name('accounts.payroll.runs.store');
Route::patch('accounts/{account}/payroll/runs/{payrollRun}/void', [PayrollRunController::class, 'void'])
    ->scopeBindings()
    ->name('accounts.payroll.runs.void');
Route::get('accounts/{account}/reports/trainers', TrainerReportController::class)
    ->name('accounts.reports.trainers');
Route::get('accounts/{account}/reports/trainers/{trainer}/private-lessons', TrainerPrivateLessonReportController::class)
    ->scopeBindings()
    ->name('accounts.reports.trainers.private-lessons');
Route::get('accounts/{account}/reports/trainers/{trainer}/salary', TrainerSalaryReportController::class)
    ->scopeBindings()
    ->name('accounts.reports.trainers.salary');
Route::get('accounts/{account}/salary-models', [SalaryModelController::class, 'index'])
    ->name('accounts.salary-models.index');
Route::get('accounts/{account}/salary-models/create', [SalaryModelController::class, 'create'])
    ->name('accounts.salary-models.create');
Route::post('accounts/{account}/salary-models', [SalaryModelController::class, 'store'])
    ->name('accounts.salary-models.store');
Route::get('accounts/{account}/salary-models/{salaryModel}/edit', [SalaryModelController::class, 'edit'])
    ->scopeBindings()
    ->name('accounts.salary-models.edit');
Route::put('accounts/{account}/salary-models/{salaryModel}', [SalaryModelController::class, 'update'])
    ->scopeBindings()
    ->name('accounts.salary-models.update');
Route::patch('accounts/{account}/salary-models/{salaryModel}/archive', [SalaryModelController::class, 'archive'])
    ->scopeBindings()
    ->name('accounts.salary-models.archive');
Route::post('accounts/{account}/salary-model-assignments', [TrainerSalaryAssignmentController::class, 'store'])
    ->name('accounts.salary-model-assignments.store');
Route::get('accounts/{account}/reports/unpaid-class-payments', UnpaidClassPaymentReportController::class)
    ->name('accounts.reports.unpaid-class-payments');
Route::get('accounts/{account}/reports/people-counter', PeopleCounterReportController::class)
    ->name('accounts.reports.people-counter');
Route::get('accounts/{account}/reports/unknown-presence', UnknownPresenceReportController::class)
    ->name('accounts.reports.unknown-presence');
Route::get('accounts/{account}/people-counter-samples/{peopleCounterSample}/{variant}', PeopleCounterScreenshotController::class)
    ->whereIn('variant', ['original', 'masked'])
    ->name('accounts.people-counter-samples.image');

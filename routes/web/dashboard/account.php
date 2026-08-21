<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountNotificationSettingsController;
use App\Http\Controllers\AccountOwnerProfileController;
use App\Http\Controllers\AccountPaymentController;
use App\Http\Controllers\AccountPaymentMethodController;
use App\Http\Controllers\AccountQrLinksController;
use App\Http\Controllers\AccountSubscriptionController;
use App\Http\Controllers\AccountTariffPaymentController;
use App\Http\Controllers\CashboxReconciliationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceEpochController;
use App\Http\Controllers\StudioCashController;
use App\Http\Controllers\StudioExpenseController;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('index');
Route::resource('accounts', AccountController::class);
Route::get('accounts/{account}/general-settings', [AccountController::class, 'editBrand'])
    ->name('accounts.general-settings.edit');
Route::get('accounts/{account}/qr-links', AccountQrLinksController::class)
    ->name('accounts.qr-links.show');
Route::get('accounts/{account}/notification-settings', [AccountNotificationSettingsController::class, 'edit'])
    ->name('accounts.notification-settings.edit');
Route::get('accounts/{account}/brand', function (Request $request, Account $account): RedirectResponse {
    if ($request->query('tab') === 'api') {
        return redirect()->route('dashboard.accounts.integrations.index', [$account, 'tab' => 'api']);
    }

    return redirect()->route('dashboard.accounts.general-settings.edit', ['account' => $account] + $request->query());
})
    ->name('accounts.brand.edit');
Route::get('accounts/{account}/owner-profile', [AccountOwnerProfileController::class, 'edit'])
    ->name('accounts.owner-profile.edit');
Route::put('accounts/{account}/owner-profile', [AccountOwnerProfileController::class, 'update'])
    ->name('accounts.owner-profile.update');
Route::get('accounts/{account}/tariff-payments', [AccountTariffPaymentController::class, 'show'])
    ->name('accounts.tariff-payments.show');
Route::post('accounts/{account}/tariff-payments/pay-now', [AccountTariffPaymentController::class, 'payNow'])
    ->name('accounts.tariff-payments.pay-now');
Route::post('accounts/{account}/tariff-payments/subscribe', [AccountSubscriptionController::class, 'subscribe'])
    ->name('accounts.tariff-payments.subscribe');
Route::delete('accounts/{account}/tariff-payments/subscription', [AccountSubscriptionController::class, 'cancel'])
    ->name('accounts.tariff-payments.cancel');
Route::post('accounts/{account}/tariff-payments/subscription/resume', [AccountSubscriptionController::class, 'resume'])
    ->name('accounts.tariff-payments.resume');
Route::post('accounts/{account}/payment-method/change', AccountPaymentMethodController::class)
    ->name('accounts.payment-method.change');
Route::post('accounts/{account}/tariff-payments/locations/{location}/approve', [AccountSubscriptionController::class, 'approveLocation'])
    ->scopeBindings()
    ->name('accounts.tariff-payments.locations.approve');
Route::get('accounts/{account}/payments', [AccountPaymentController::class, 'index'])
    ->name('accounts.payments.index');
Route::get('accounts/{account}/cash', [StudioCashController::class, 'index'])
    ->name('accounts.cash.index');
Route::get('accounts/{account}/expenses', [StudioExpenseController::class, 'index'])
    ->name('accounts.expenses.index');
Route::post('accounts/{account}/finance-epochs', FinanceEpochController::class)
    ->name('accounts.finance-epochs.store');
Route::post('accounts/{account}/cashbox-reconciliations', CashboxReconciliationController::class)
    ->name('accounts.cashbox-reconciliations.store');

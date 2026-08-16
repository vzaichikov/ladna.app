<?php

use App\Http\Controllers\AdminCustomerLoginController;
use App\Http\Controllers\CustomerBulkTransferController;
use App\Http\Controllers\CustomerClassPassController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerSearchController;
use App\Http\Controllers\EventFestivalStaffController;
use App\Http\Controllers\StudioSettingsController;
use App\Http\Controllers\TrainerController;
use App\Http\Controllers\TrainerPrivateTimeframeController;
use App\Http\Controllers\TrainerSubstitutionController;
use App\Http\Controllers\TrainerTypeController;
use App\Http\Controllers\WebsiteLeadController;
use Illuminate\Support\Facades\Route;

Route::get('accounts/{account}/customer-class-passes', [CustomerClassPassController::class, 'index'])
    ->name('accounts.customer-class-passes.index');
Route::get('accounts/{account}/customer-class-passes/{customerClassPass}/edit', [CustomerClassPassController::class, 'edit'])
    ->name('accounts.customer-class-passes.edit');
Route::put('accounts/{account}/customer-class-passes/{customerClassPass}', [CustomerClassPassController::class, 'update'])
    ->name('accounts.customer-class-passes.update');
Route::post('accounts/{account}/customer-class-passes/{customerClassPass}/payments', [CustomerClassPassController::class, 'storePayment'])
    ->name('accounts.customer-class-passes.payments.store');
Route::post('accounts/{account}/customer-class-passes/{customerClassPass}/adjustments', [CustomerClassPassController::class, 'storeAdjustment'])
    ->name('accounts.customer-class-passes.adjustments.store');
Route::post('accounts/{account}/customer-class-passes/{customerClassPass}/validity-adjustments', [CustomerClassPassController::class, 'storeValidityAdjustment'])
    ->name('accounts.customer-class-passes.validity-adjustments.store');
Route::post('accounts/{account}/customer-class-passes/{customerClassPass}/freeze', [CustomerClassPassController::class, 'freeze'])
    ->name('accounts.customer-class-passes.freeze');
Route::post('accounts/{account}/customer-class-passes/{customerClassPass}/unfreeze', [CustomerClassPassController::class, 'unfreeze'])
    ->name('accounts.customer-class-passes.unfreeze');
Route::get('accounts/{account}/trainers/{trainer}/substitutions/classes', [TrainerSubstitutionController::class, 'classes'])
    ->name('accounts.trainers.substitutions.classes');
Route::post('accounts/{account}/trainers/{trainer}/substitutions', [TrainerSubstitutionController::class, 'store'])
    ->name('accounts.trainers.substitutions.store');
Route::put('accounts/{account}/trainers/{trainer}/substitutions/{trainerSubstitution}', [TrainerSubstitutionController::class, 'update'])
    ->name('accounts.trainers.substitutions.update');
Route::delete('accounts/{account}/trainers/{trainer}/substitutions/{trainerSubstitution}', [TrainerSubstitutionController::class, 'destroy'])
    ->name('accounts.trainers.substitutions.destroy');
Route::get('accounts/{account}/trainer-private-timeframes', [TrainerPrivateTimeframeController::class, 'mine'])
    ->name('accounts.trainer-private-timeframes.mine');
Route::resource('accounts.event-festival-staff', EventFestivalStaffController::class)
    ->parameters(['event-festival-staff' => 'membership'])
    ->except(['show'])
    ->scoped();
Route::get('accounts/{account}/trainers/{trainer}/private-timeframes', [TrainerPrivateTimeframeController::class, 'edit'])
    ->name('accounts.trainers.private-timeframes.edit');
Route::post('accounts/{account}/trainers/{trainer}/private-timeframes/toggle', [TrainerPrivateTimeframeController::class, 'toggle'])
    ->name('accounts.trainers.private-timeframes.toggle');
Route::resource('accounts.trainers', TrainerController::class)
    ->except(['show'])
    ->scoped();
Route::get('accounts/{account}/studio-settings', [StudioSettingsController::class, 'index'])
    ->name('accounts.studio-settings.index');
Route::resource('accounts.trainer-types', TrainerTypeController::class)
    ->only(['index', 'store', 'update', 'destroy'])
    ->scoped();
Route::get('accounts/{account}/customers/export', [CustomerBulkTransferController::class, 'export'])
    ->name('accounts.customers.export');
Route::get('accounts/{account}/customers/import-example', [CustomerBulkTransferController::class, 'example'])
    ->name('accounts.customers.example');
Route::post('accounts/{account}/customers/import/validate', [CustomerBulkTransferController::class, 'validateImport'])
    ->name('accounts.customers.import.validate');
Route::post('accounts/{account}/customers/import', [CustomerBulkTransferController::class, 'import'])
    ->name('accounts.customers.import');
Route::post('accounts/{account}/customers/{customer}/admin-login', [AdminCustomerLoginController::class, 'store'])
    ->name('accounts.customers.admin-login.store');
Route::resource('accounts.customers', CustomerController::class)
    ->except(['show'])
    ->scoped();
Route::get('accounts/{account}/website-leads', [WebsiteLeadController::class, 'index'])
    ->name('accounts.website-leads.index');
Route::post('accounts/{account}/website-leads', [WebsiteLeadController::class, 'store'])
    ->name('accounts.website-leads.store');
Route::patch('accounts/{account}/website-leads/{websiteLead}', [WebsiteLeadController::class, 'update'])
    ->name('accounts.website-leads.update');
Route::delete('accounts/{account}/website-leads/{websiteLead}', [WebsiteLeadController::class, 'destroy'])
    ->name('accounts.website-leads.destroy');
Route::post('accounts/{account}/customers/{customer}/class-passes', [CustomerClassPassController::class, 'store'])
    ->name('accounts.customers.class-passes.store');
Route::post('accounts/{account}/customers/{customer}/class-passes/backfill', [CustomerClassPassController::class, 'backfill'])
    ->name('accounts.customers.class-passes.backfill');
Route::get('accounts/{account}/customers/search', CustomerSearchController::class)
    ->name('accounts.customers.search');

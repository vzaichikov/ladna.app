<?php

use App\Http\Controllers\AccountIntegrationController;
use App\Http\Controllers\AccountMcpConnectionController;
use App\Http\Controllers\AccountSmsSendingSettingsController;
use App\Http\Controllers\CheckboxFiscalReceiptLogController;
use App\Http\Controllers\ClassBookingController;
use App\Http\Controllers\ClassBookingPaymentController;
use App\Http\Controllers\ClassBookingPaymentWaiverController;
use App\Http\Controllers\ClosedClassBookingCorrectionController;
use App\Http\Controllers\ManualScheduledClassController;
use App\Http\Controllers\QuickBookingController;
use App\Http\Controllers\ScheduledClassCancellationController;
use App\Http\Controllers\ScheduledClassController;
use App\Http\Controllers\ScheduledClassHistoryController;
use App\Http\Controllers\ScheduledClassTrainerController;
use App\Http\Controllers\ScheduleSeriesController;
use Illuminate\Support\Facades\Route;

Route::resource('accounts.schedule-series', ScheduleSeriesController::class)
    ->except(['show'])
    ->scoped();
Route::get('accounts/{account}/integrations', [AccountMcpConnectionController::class, 'index'])
    ->name('accounts.integrations.index');
Route::delete('accounts/{account}/integrations/ai/{mcpOAuthConnection}', [AccountMcpConnectionController::class, 'destroy'])
    ->name('accounts.integrations.mcp-connections.destroy');
Route::get('accounts/{account}/integrations/fiscalization/checkbox/logs', CheckboxFiscalReceiptLogController::class)
    ->name('accounts.integrations.checkbox-logs.index');
Route::get('accounts/{account}/integrations/{category}', [AccountIntegrationController::class, 'show'])
    ->whereIn('category', ['payment', 'fiscalization', 'messaging'])
    ->name('accounts.integrations.show');
Route::put('accounts/{account}/integrations/sms-sending', [AccountSmsSendingSettingsController::class, 'update'])
    ->name('accounts.integrations.sms-sending.update');
Route::put('accounts/{account}/integrations/{provider}', [AccountIntegrationController::class, 'update'])
    ->name('accounts.integrations.update');
Route::get('accounts/{account}/scheduled-classes', ScheduledClassController::class)
    ->name('accounts.scheduled-classes.index');
Route::get('accounts/{account}/scheduled-classes-history', ScheduledClassHistoryController::class)
    ->name('accounts.scheduled-classes-history.index');
Route::post('accounts/{account}/quick-bookings', [QuickBookingController::class, 'store'])
    ->name('accounts.quick-bookings.store');
Route::get('accounts/{account}/quick-bookings/group-availability', [QuickBookingController::class, 'groupAvailability'])
    ->name('accounts.quick-bookings.group-availability');
Route::get('accounts/{account}/quick-bookings/manual-availability', [QuickBookingController::class, 'manualAvailability'])
    ->name('accounts.quick-bookings.manual-availability');
Route::post('accounts/{account}/scheduled-classes/manual/{scheduleKind}', [ManualScheduledClassController::class, 'store'])
    ->name('accounts.scheduled-classes.manual.store');
Route::patch('accounts/{account}/scheduled-classes/{scheduledClass}/internal', [ManualScheduledClassController::class, 'update'])
    ->name('accounts.scheduled-classes.internal.update');
Route::patch('accounts/{account}/scheduled-classes/{scheduledClass}/trainer', [ScheduledClassTrainerController::class, 'update'])
    ->name('accounts.scheduled-classes.trainer.update');
Route::patch('accounts/{account}/scheduled-classes/{scheduledClass}/cancel', [ScheduledClassCancellationController::class, 'cancel'])
    ->name('accounts.scheduled-classes.cancel');
Route::patch('accounts/{account}/scheduled-classes/{scheduledClass}/cancel-closed', [ScheduledClassCancellationController::class, 'cancelClosed'])
    ->name('accounts.scheduled-classes.cancel-closed');
Route::patch('accounts/{account}/scheduled-classes/{scheduledClass}/restore', [ScheduledClassCancellationController::class, 'restore'])
    ->name('accounts.scheduled-classes.restore');
Route::get('accounts/{account}/scheduled-classes/{scheduledClass}/corrections/pass-preview', [ClosedClassBookingCorrectionController::class, 'preview'])
    ->name('accounts.scheduled-classes.corrections.pass-preview');
Route::post('accounts/{account}/scheduled-classes/{scheduledClass}/corrections/bookings', [ClosedClassBookingCorrectionController::class, 'store'])
    ->name('accounts.scheduled-classes.corrections.bookings.store');
Route::post('accounts/{account}/scheduled-classes/{scheduledClass}/bookings', [ClassBookingController::class, 'store'])
    ->name('accounts.scheduled-classes.bookings.store');
Route::patch('accounts/{account}/bookings/{classBooking}', [ClassBookingController::class, 'update'])
    ->name('accounts.bookings.update');
Route::post('accounts/{account}/bookings/{classBooking}/corrections/remove', [ClosedClassBookingCorrectionController::class, 'remove'])
    ->name('accounts.bookings.corrections.remove');
Route::post('accounts/{account}/bookings/{classBooking}/payment', [ClassBookingPaymentController::class, 'store'])
    ->name('accounts.bookings.payment.store');
Route::post('accounts/{account}/bookings/{classBooking}/payment-waivers', [ClassBookingPaymentWaiverController::class, 'store'])
    ->scopeBindings()
    ->name('accounts.bookings.payment-waivers.store');
Route::patch('accounts/{account}/booking-payment-waivers/{classBookingPaymentWaiver}/unwaive', [ClassBookingPaymentWaiverController::class, 'unwaive'])
    ->scopeBindings()
    ->name('accounts.booking-payment-waivers.unwaive');
Route::delete('accounts/{account}/bookings/{classBooking}', [ClassBookingController::class, 'destroy'])
    ->name('accounts.bookings.destroy');

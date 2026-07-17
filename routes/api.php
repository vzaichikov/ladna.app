<?php

use App\Http\Controllers\Api\V1\Mobile\MobileAuthController;
use App\Http\Controllers\Api\V1\Mobile\MobileBookingController;
use App\Http\Controllers\Api\V1\Mobile\MobileCustomerController;
use App\Http\Controllers\Api\V1\Mobile\MobileDeviceTokenController;
use App\Http\Controllers\Api\V1\Mobile\MobileScheduleController;
use App\Http\Controllers\Api\V1\Mobile\MobileStudioController;
use App\Http\Controllers\Api\V1\PublicPriceController;
use App\Http\Controllers\Api\V1\PublicScheduleController;
use App\Http\Controllers\Api\V1\TelegramWebhookController;
use App\Http\Controllers\Api\V1\WebsiteLeadController;
use App\Http\Controllers\Payments\CustomerPurchaseCallbackController;
use App\Http\Controllers\Payments\SaasPaymentCallbackController;
use App\Http\Middleware\AuthenticateAccountApiToken;
use App\Http\Middleware\AuthenticateMobileSession;
use App\Http\Middleware\EnsurePublicSubscriptionIsActive;
use App\Http\Middleware\PreventReadOnlyDemoMutations;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/public/{accountSlug}/{locationSlug}')
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->group(function (): void {
        Route::get('/schedule', [PublicScheduleController::class, 'schedule'])->name('api.v1.public.schedule');
        Route::get('/classes', [PublicScheduleController::class, 'classes'])->name('api.v1.public.classes');
        Route::get('/price', PublicPriceController::class)->name('api.v1.public.price');
    });

Route::post('v1/website-leads', WebsiteLeadController::class)
    ->middleware([AuthenticateAccountApiToken::class.':website_leads:create', PreventReadOnlyDemoMutations::class, EnsurePublicSubscriptionIsActive::class, 'throttle:website-leads'])
    ->name('api.v1.website-leads.store');

Route::prefix('v1/mobile')->name('api.v1.mobile.')->group(function (): void {
    Route::get('studios/{accountSlug}', [MobileStudioController::class, 'show'])
        ->middleware('throttle:mobile-api')
        ->name('studios.show');

    Route::prefix('auth')->name('auth.')->middleware('throttle:mobile-auth')->group(function (): void {
        Route::post('staff/login', [MobileAuthController::class, 'staffLogin'])->name('staff.login');
        Route::post('customer/email-login', [MobileAuthController::class, 'customerEmailLogin'])->middleware(PreventReadOnlyDemoMutations::class)->name('customer.email-login');
        Route::post('customer/otp/send', [MobileAuthController::class, 'customerOtpSend'])->middleware(PreventReadOnlyDemoMutations::class)->name('customer.otp.send');
        Route::post('customer/otp/verify', [MobileAuthController::class, 'customerOtpVerify'])->middleware(PreventReadOnlyDemoMutations::class)->name('customer.otp.verify');
        Route::get('customer/google/{accountSlug}/redirect', [MobileAuthController::class, 'customerGoogleRedirect'])->name('customer.google.redirect');
        Route::get('customer/google/callback', [MobileAuthController::class, 'customerGoogleCallback'])->name('customer.google.callback');
        Route::post('customer/google/exchange', [MobileAuthController::class, 'customerGoogleExchange'])->name('customer.google.exchange');
    });

    Route::middleware([AuthenticateMobileSession::class, PreventReadOnlyDemoMutations::class, 'throttle:mobile-api'])->group(function (): void {
        Route::get('me', [MobileAuthController::class, 'me'])->name('me');
        Route::post('logout', [MobileAuthController::class, 'logout'])->name('logout');
        Route::post('device-tokens', [MobileDeviceTokenController::class, 'store'])->name('device-tokens.store');
        Route::get('schedule', [MobileScheduleController::class, 'index'])->name('schedule.index');
        Route::get('classes/{scheduledClass}', [MobileScheduleController::class, 'show'])->name('classes.show');
        Route::post('classes/{scheduledClass}/customer-booking', [MobileBookingController::class, 'customerStore'])->name('classes.customer-booking.store');
        Route::post('classes/{scheduledClass}/staff-bookings', [MobileBookingController::class, 'staffStore'])->name('classes.staff-bookings.store');
        Route::patch('bookings/{classBooking}', [MobileBookingController::class, 'updateStatus'])->name('bookings.update');
        Route::delete('bookings/{classBooking}', [MobileBookingController::class, 'cancel'])->name('bookings.cancel');
        Route::get('customer/bookings', [MobileCustomerController::class, 'bookings'])->name('customer.bookings');
        Route::get('customer/passes', [MobileCustomerController::class, 'passes'])->name('customer.passes');
        Route::put('customer/profile', [MobileCustomerController::class, 'updateProfile'])->name('customer.profile.update');
        Route::post('customer/profile/phone/send', [MobileCustomerController::class, 'sendProfilePhoneOtp'])->name('customer.profile.phone.send');
        Route::post('customer/profile/phone/resend', [MobileCustomerController::class, 'resendProfilePhoneOtp'])->name('customer.profile.phone.resend');
        Route::post('customer/profile/phone/verify', [MobileCustomerController::class, 'verifyProfilePhoneOtp'])->name('customer.profile.phone.verify');
        Route::get('staff/customers', [MobileCustomerController::class, 'search'])->name('staff.customers.index');
    });
});

Route::post('v1/telegram/webhooks/{webhookKey}', TelegramWebhookController::class)
    ->name('api.v1.telegram.webhooks.handle');

Route::post('v1/payments/{provider}/callbacks', [CustomerPurchaseCallbackController::class, 'store'])
    ->name('api.v1.payments.callbacks');

Route::post('v1/saas/payments/{provider}/callbacks', [SaasPaymentCallbackController::class, 'store'])
    ->name('api.v1.saas.payments.callbacks');

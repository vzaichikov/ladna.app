<?php

use App\Http\Controllers\AdminCustomerLoginController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\CustomerBookingCancellationController;
use App\Http\Controllers\CustomerPurchaseReturnController;
use App\Http\Controllers\FestivalPortalAuthController;
use App\Http\Controllers\TelegramCustomerLoginController;
use App\Http\Middleware\EnsureCustomerIsAuthenticated;
use App\Http\Middleware\EnsureCustomerProfileIsComplete;
use App\Http\Middleware\EnsurePublicSubscriptionIsActive;
use App\Http\Middleware\PreventReadOnlyDemoMutations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::get('/customer/login', [CustomerAuthController::class, 'create'])->name('customer.login');
Route::get('/customer/auth/google/callback', [CustomerAuthController::class, 'googleCallback'])->name('customer.google.callback');
Route::get('/festival/auth/google/callback', [FestivalPortalAuthController::class, 'googleCallback'])->name('festival.google.callback');
Route::get('/customer/studios', [CustomerAuthController::class, 'studios'])->name('customer.studios.index');
Route::post('/customer/studios/{customerId}/switch', [CustomerAuthController::class, 'switchStudio'])
    ->whereNumber('customerId')
    ->name('customer.studios.switch');

Route::prefix('{accountSlug}/customer')
    ->name('customer.')
    ->middleware([PreventReadOnlyDemoMutations::class, EnsurePublicSubscriptionIsActive::class])
    ->group(function (): void {
        Route::get('login', [CustomerAuthController::class, 'studioLogin'])->name('studio.login');
        Route::get('admin-login/{token}', [AdminCustomerLoginController::class, 'consume'])
            ->middleware(['signed', 'throttle:customer-login'])
            ->where('token', '[A-Za-z0-9]+')
            ->name('admin-login.consume');
        Route::get('telegram-login/{token}', TelegramCustomerLoginController::class)
            ->middleware(['signed', 'throttle:customer-login'])
            ->where('token', '[A-Za-z0-9]{64}')
            ->name('telegram-login.consume');
        Route::post('login/email', [CustomerAuthController::class, 'emailLogin'])->middleware('throttle:customer-login')->name('email.login');
        Route::post('login/otp', [CustomerAuthController::class, 'sendOtp'])->middleware('throttle:customer-otp')->name('otp.send');
        Route::get('login/otp', [CustomerAuthController::class, 'otpChallenge'])->name('otp.challenge');
        Route::post('login/otp/resend', [CustomerAuthController::class, 'resendOtp'])->middleware('throttle:customer-otp')->name('otp.resend');
        Route::post('login/otp/change-phone', [CustomerAuthController::class, 'changeOtpPhone'])->name('otp.change-phone');
        Route::post('login/otp/verify', [CustomerAuthController::class, 'verifyOtp'])->middleware('throttle:customer-login')->name('otp.verify');
        Route::get('auth/google', [CustomerAuthController::class, 'googleRedirect'])->name('google.redirect');
        Route::get('auth/google/phone', [CustomerAuthController::class, 'googlePhone'])->name('google.phone');
        Route::post('auth/google/phone', [CustomerAuthController::class, 'sendGooglePhoneOtp'])->middleware('throttle:customer-otp')->name('google.phone.send');
        Route::post('auth/google/phone/resend', [CustomerAuthController::class, 'resendGooglePhoneOtp'])->middleware('throttle:customer-otp')->name('google.phone.resend');
        Route::post('auth/google/phone/change-phone', [CustomerAuthController::class, 'changeGooglePhone'])->name('google.phone.change');
        Route::post('auth/google/phone/verify', [CustomerAuthController::class, 'verifyGooglePhoneOtp'])->middleware('throttle:customer-login')->name('google.phone.verify');

        Route::middleware(EnsureCustomerIsAuthenticated::class)->group(function (): void {
            Route::get('profile/complete', [CustomerAuthController::class, 'editProfile'])->name('profile.complete');
            Route::put('profile', [CustomerAuthController::class, 'updateProfile'])->name('profile.update');
            Route::post('profile/phone/send', [CustomerAuthController::class, 'sendProfilePhoneOtp'])->middleware('throttle:customer-otp')->name('profile.phone.send');
            Route::post('profile/phone/resend', [CustomerAuthController::class, 'resendProfilePhoneOtp'])->middleware('throttle:customer-otp')->name('profile.phone.resend');
            Route::post('profile/phone/change', [CustomerAuthController::class, 'changeProfilePhone'])->name('profile.phone.change');
            Route::post('profile/phone/verify', [CustomerAuthController::class, 'verifyProfilePhoneOtp'])->middleware('throttle:customer-login')->name('profile.phone.verify');
            Route::post('logout', [CustomerAuthController::class, 'logout'])->name('logout');

            Route::middleware(EnsureCustomerProfileIsComplete::class)->group(function (): void {
                Route::get('/', [CustomerAuthController::class, 'studioDashboard'])->name('dashboard');
                Route::get('profile', [CustomerAuthController::class, 'editProfile'])->name('profile.edit');
                Route::patch('bookings/{classBooking}/cancel', CustomerBookingCancellationController::class)->name('bookings.cancel');
                Route::get('purchases/{customerPurchase}/return', CustomerPurchaseReturnController::class)->name('purchases.return');
            });
        });
    });

Route::get('/{accountSlug}/client/login', fn (string $accountSlug): RedirectResponse => redirect()->route('customer.studio.login', $accountSlug))
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('customer.legacy.login');
Route::get('/{accountSlug}/client', fn (string $accountSlug): RedirectResponse => redirect()->route('customer.dashboard', $accountSlug))
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('customer.studio.dashboard');

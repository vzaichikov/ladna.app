<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountIntegrationController;
use App\Http\Controllers\AccountOwnerProfileController;
use App\Http\Controllers\ActivityDirectionController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ChangelogController;
use App\Http\Controllers\ClassBookingController;
use App\Http\Controllers\ClassPassPlanController;
use App\Http\Controllers\ClassTypeController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\CustomerClassPassController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerSearchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LegalPageController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\Platform\CustomerAuthSettingsController as PlatformCustomerAuthSettingsController;
use App\Http\Controllers\Platform\IntegrationController as PlatformIntegrationController;
use App\Http\Controllers\Platform\PlatformAccountController;
use App\Http\Controllers\Platform\PlatformController;
use App\Http\Controllers\Platform\ProfileController as PlatformProfileController;
use App\Http\Controllers\Platform\SubscriptionPlanController;
use App\Http\Controllers\Platform\SystemSettingsController;
use App\Http\Controllers\PublicPriceController;
use App\Http\Controllers\PublicScheduleController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\ScheduledClassController;
use App\Http\Controllers\ScheduleSeriesController;
use App\Http\Controllers\StudioSettingsController;
use App\Http\Controllers\TrainerController;
use App\Http\Controllers\TrainerTypeController;
use App\Http\Middleware\EnsureCustomerIsAuthenticated;
use App\Http\Middleware\EnsureCustomerProfileIsComplete;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('/changelog.en.html', [ChangelogController::class, 'english'])->name('changelog.en');
Route::get('/changelog.ua.html', [ChangelogController::class, 'ukrainian'])->name('changelog.ua');
Route::get('/terms.en.html', [LegalPageController::class, 'termsEnglish'])->name('terms.en');
Route::get('/terms.ua.html', [LegalPageController::class, 'termsUkrainian'])->name('terms.ua');
Route::get('/privacy.en.html', [LegalPageController::class, 'privacyEnglish'])->name('privacy.en');
Route::get('/privacy.ua.html', [LegalPageController::class, 'privacyUkrainian'])->name('privacy.ua');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth:web')
    ->name('logout');

Route::post('/locale', LocaleController::class)->name('locale.update');
Route::get('/customer/login', [CustomerAuthController::class, 'create'])->name('customer.login');
Route::get('/customer/auth/google/callback', [CustomerAuthController::class, 'googleCallback'])->name('customer.google.callback');

Route::prefix('{accountSlug}/customer')
    ->name('customer.')
    ->group(function (): void {
        Route::get('login', [CustomerAuthController::class, 'studioLogin'])->name('studio.login');
        Route::post('login/email', [CustomerAuthController::class, 'emailLogin'])->middleware('throttle:customer-login')->name('email.login');
        Route::post('login/otp', [CustomerAuthController::class, 'sendOtp'])->middleware('throttle:customer-otp')->name('otp.send');
        Route::get('login/otp', [CustomerAuthController::class, 'otpChallenge'])->name('otp.challenge');
        Route::post('login/otp/resend', [CustomerAuthController::class, 'resendOtp'])->middleware('throttle:customer-otp')->name('otp.resend');
        Route::post('login/otp/change-phone', [CustomerAuthController::class, 'changeOtpPhone'])->name('otp.change-phone');
        Route::post('login/otp/verify', [CustomerAuthController::class, 'verifyOtp'])->middleware('throttle:customer-login')->name('otp.verify');
        Route::get('auth/google', [CustomerAuthController::class, 'googleRedirect'])->name('google.redirect');

        Route::middleware(EnsureCustomerIsAuthenticated::class)->group(function (): void {
            Route::get('profile/complete', [CustomerAuthController::class, 'editProfile'])->name('profile.complete');
            Route::put('profile', [CustomerAuthController::class, 'updateProfile'])->name('profile.update');
            Route::post('logout', [CustomerAuthController::class, 'logout'])->name('logout');

            Route::middleware(EnsureCustomerProfileIsComplete::class)->group(function (): void {
                Route::get('/', [CustomerAuthController::class, 'studioDashboard'])->name('dashboard');
                Route::get('profile', [CustomerAuthController::class, 'editProfile'])->name('profile.edit');
            });
        });
    });

Route::get('/{accountSlug}/client/login', fn (string $accountSlug): RedirectResponse => redirect()->route('customer.studio.login', $accountSlug))
    ->name('customer.legacy.login');
Route::get('/{accountSlug}/client', fn (string $accountSlug): RedirectResponse => redirect()->route('customer.dashboard', $accountSlug))
    ->name('customer.studio.dashboard');

Route::middleware(['auth:web', 'can:accessPlatform'])
    ->prefix('platform')
    ->name('platform.')
    ->group(function (): void {
        Route::get('/', PlatformController::class)->name('index');
        Route::get('account', [PlatformProfileController::class, 'edit'])->name('account.edit');
        Route::put('account', [PlatformProfileController::class, 'update'])->name('account.update');
        Route::get('settings', [SystemSettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [SystemSettingsController::class, 'update'])->name('settings.update');
        Route::get('integrations', [PlatformIntegrationController::class, 'index'])->name('integrations.index');
        Route::put('integrations/{provider}', [PlatformIntegrationController::class, 'update'])->name('integrations.update');
        Route::resource('accounts', PlatformAccountController::class);
        Route::get('accounts/{account}/customer-auth', [PlatformCustomerAuthSettingsController::class, 'edit'])
            ->name('accounts.customer-auth.edit');
        Route::put('accounts/{account}/customer-auth', [PlatformCustomerAuthSettingsController::class, 'update'])
            ->name('accounts.customer-auth.update');
        Route::resource('subscription-plans', SubscriptionPlanController::class)->except(['show']);
    });

Route::middleware('auth:web')
    ->prefix('dashboard')
    ->name('dashboard.')
    ->group(function (): void {
        Route::get('/', DashboardController::class)->name('index');
        Route::resource('accounts', AccountController::class);
        Route::get('accounts/{account}/brand', [AccountController::class, 'editBrand'])
            ->name('accounts.brand.edit');
        Route::get('accounts/{account}/owner-profile', [AccountOwnerProfileController::class, 'edit'])
            ->name('accounts.owner-profile.edit');
        Route::put('accounts/{account}/owner-profile', [AccountOwnerProfileController::class, 'update'])
            ->name('accounts.owner-profile.update');
        Route::resource('accounts.locations', LocationController::class)
            ->except(['show'])
            ->scoped();
        Route::resource('accounts.rooms', RoomController::class)
            ->except(['show'])
            ->scoped();
        Route::resource('accounts.activity-directions', ActivityDirectionController::class)
            ->except(['show'])
            ->scoped();
        Route::resource('accounts.class-types', ClassTypeController::class)
            ->except(['show'])
            ->scoped();
        Route::resource('accounts.class-pass-plans', ClassPassPlanController::class)
            ->except(['show'])
            ->scoped();
        Route::get('accounts/{account}/customer-class-passes', [CustomerClassPassController::class, 'index'])
            ->name('accounts.customer-class-passes.index');
        Route::get('accounts/{account}/customer-class-passes/{customerClassPass}/edit', [CustomerClassPassController::class, 'edit'])
            ->name('accounts.customer-class-passes.edit');
        Route::put('accounts/{account}/customer-class-passes/{customerClassPass}', [CustomerClassPassController::class, 'update'])
            ->name('accounts.customer-class-passes.update');
        Route::resource('accounts.trainers', TrainerController::class)
            ->except(['show'])
            ->scoped();
        Route::get('accounts/{account}/studio-settings', [StudioSettingsController::class, 'index'])
            ->name('accounts.studio-settings.index');
        Route::resource('accounts.trainer-types', TrainerTypeController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->scoped();
        Route::resource('accounts.customers', CustomerController::class)
            ->except(['show'])
            ->scoped();
        Route::post('accounts/{account}/customers/{customer}/class-passes', [CustomerClassPassController::class, 'store'])
            ->name('accounts.customers.class-passes.store');
        Route::get('accounts/{account}/customers/search', CustomerSearchController::class)
            ->name('accounts.customers.search');
        Route::resource('accounts.schedule-series', ScheduleSeriesController::class)
            ->except(['show'])
            ->scoped();
        Route::get('accounts/{account}/integrations', [AccountIntegrationController::class, 'index'])
            ->name('accounts.integrations.index');
        Route::put('accounts/{account}/integrations/{provider}', [AccountIntegrationController::class, 'update'])
            ->name('accounts.integrations.update');
        Route::get('accounts/{account}/scheduled-classes', ScheduledClassController::class)
            ->name('accounts.scheduled-classes.index');
        Route::post('accounts/{account}/scheduled-classes/{scheduledClass}/bookings', [ClassBookingController::class, 'store'])
            ->name('accounts.scheduled-classes.bookings.store');
        Route::patch('accounts/{account}/bookings/{classBooking}', [ClassBookingController::class, 'update'])
            ->name('accounts.bookings.update');
        Route::delete('accounts/{account}/bookings/{classBooking}', [ClassBookingController::class, 'destroy'])
            ->name('accounts.bookings.destroy');
    });

Route::get('/{accountSlug}/{locationSlug}/schedule', [PublicScheduleController::class, 'show'])
    ->name('public.schedule');
Route::get('/{accountSlug}/{locationSlug}/schedule/embed', [PublicScheduleController::class, 'embed'])
    ->name('public.schedule.embed');
Route::get('/{accountSlug}/{locationSlug}/price', [PublicPriceController::class, 'show'])
    ->name('public.price');
Route::get('/{accountSlug}/{locationSlug}/price/embed', [PublicPriceController::class, 'embed'])
    ->name('public.price.embed');

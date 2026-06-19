<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountIntegrationController;
use App\Http\Controllers\AccountOwnerProfileController;
use App\Http\Controllers\ActivityDirectionController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ClassBookingController;
use App\Http\Controllers\ClassPassPlanController;
use App\Http\Controllers\ClassTypeController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\Platform\IntegrationController as PlatformIntegrationController;
use App\Http\Controllers\Platform\PlatformAccountController;
use App\Http\Controllers\Platform\PlatformController;
use App\Http\Controllers\Platform\ProfileController as PlatformProfileController;
use App\Http\Controllers\Platform\SubscriptionPlanController;
use App\Http\Controllers\Platform\SystemSettingsController;
use App\Http\Controllers\PublicScheduleController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\ScheduledClassController;
use App\Http\Controllers\ScheduleSeriesController;
use App\Http\Controllers\StudioSettingsController;
use App\Http\Controllers\TrainerController;
use App\Http\Controllers\TrainerTypeController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth:web')
    ->name('logout');

Route::post('/locale', LocaleController::class)->name('locale.update');
Route::get('/customer/login', [CustomerAuthController::class, 'create'])->name('customer.login');
Route::get('/{accountSlug}/client/login', [CustomerAuthController::class, 'studioLogin'])->name('customer.studio.login');
Route::get('/{accountSlug}/client', [CustomerAuthController::class, 'studioDashboard'])->name('customer.studio.dashboard');

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
        Route::resource('subscription-plans', SubscriptionPlanController::class)->except(['show']);
    });

Route::middleware('auth:web')
    ->prefix('dashboard')
    ->name('dashboard.')
    ->group(function (): void {
        Route::get('/', DashboardController::class)->name('index');
        Route::resource('accounts', AccountController::class);
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
        Route::resource('accounts.trainers', TrainerController::class)
            ->except(['show'])
            ->scoped();
        Route::get('accounts/{account}/studio-settings', [StudioSettingsController::class, 'index'])
            ->name('accounts.studio-settings.index');
        Route::resource('accounts.trainer-types', TrainerTypeController::class)
            ->only(['store', 'update', 'destroy'])
            ->scoped();
        Route::resource('accounts.customers', CustomerController::class)
            ->except(['show'])
            ->scoped();
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

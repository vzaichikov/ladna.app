<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ActivityDirectionController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\ClassTypeController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InstructorController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Platform\PlatformAccountController;
use App\Http\Controllers\Platform\PlatformController;
use App\Http\Controllers\PublicScheduleController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\ScheduledClassController;
use App\Http\Controllers\ScheduleSeriesController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:login');
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
        Route::resource('accounts', PlatformAccountController::class);
    });

Route::middleware('auth:web')
    ->prefix('dashboard')
    ->name('dashboard.')
    ->group(function (): void {
        Route::get('/', DashboardController::class)->name('index');
        Route::resource('accounts', AccountController::class);
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
        Route::resource('accounts.instructors', InstructorController::class)
            ->except(['show'])
            ->scoped();
        Route::resource('accounts.schedule-series', ScheduleSeriesController::class)
            ->except(['show'])
            ->scoped();
        Route::get('accounts/{account}/scheduled-classes', ScheduledClassController::class)
            ->name('accounts.scheduled-classes.index');
    });

Route::get('/{accountSlug}/{locationSlug}/schedule', [PublicScheduleController::class, 'show'])
    ->name('public.schedule');
Route::get('/{accountSlug}/{locationSlug}/schedule/embed', [PublicScheduleController::class, 'embed'])
    ->name('public.schedule.embed');

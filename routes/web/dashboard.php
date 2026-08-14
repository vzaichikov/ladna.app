<?php

use App\Http\Controllers\WorkingLocationController;
use App\Http\Middleware\EnsureOwnerOnboardingComplete;
use App\Http\Middleware\PreventExpiredSubscriptionMutations;
use App\Http\Middleware\PreventReadOnlyDemoMutations;
use App\Http\Middleware\RecordAccountActivity;
use Illuminate\Support\Facades\Route;

Route::post('/app/dashboard/accounts/{account}/working-location', WorkingLocationController::class)
    ->middleware(['auth:web', EnsureOwnerOnboardingComplete::class])
    ->name('dashboard.accounts.working-location.update');

Route::middleware(['auth:web', EnsureOwnerOnboardingComplete::class, PreventReadOnlyDemoMutations::class, PreventExpiredSubscriptionMutations::class, RecordAccountActivity::class])
    ->prefix('app/dashboard')
    ->name('dashboard.')
    ->group(function (): void {
        require __DIR__.'/dashboard/account.php';
        require __DIR__.'/dashboard/events.php';
        require __DIR__.'/dashboard/festivals.php';
        require __DIR__.'/dashboard/finance.php';
        require __DIR__.'/dashboard/communications.php';
        require __DIR__.'/dashboard/studio-catalog.php';
        require __DIR__.'/dashboard/people.php';
        require __DIR__.'/dashboard/scheduling-and-integrations.php';
    });

<?php

use App\Http\Controllers\Api\V1\PublicPriceController;
use App\Http\Controllers\Api\V1\PublicScheduleController;
use App\Http\Controllers\Api\V1\WebsiteLeadController;
use App\Http\Controllers\Payments\CustomerPurchaseCallbackController;
use App\Http\Middleware\AuthenticateAccountApiToken;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/public/{accountSlug}/{locationSlug}')
    ->group(function (): void {
        Route::get('/schedule', [PublicScheduleController::class, 'schedule'])->name('api.v1.public.schedule');
        Route::get('/classes', [PublicScheduleController::class, 'classes'])->name('api.v1.public.classes');
        Route::get('/price', PublicPriceController::class)->name('api.v1.public.price');
    });

Route::post('v1/website-leads', WebsiteLeadController::class)
    ->middleware([AuthenticateAccountApiToken::class, 'throttle:website-leads'])
    ->name('api.v1.website-leads.store');

Route::post('v1/payments/{provider}/callbacks', [CustomerPurchaseCallbackController::class, 'store'])
    ->name('api.v1.payments.callbacks');

<?php

use App\Http\Controllers\Api\V1\PublicScheduleController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/public/{accountSlug}/{locationSlug}')
    ->group(function (): void {
        Route::get('/schedule', [PublicScheduleController::class, 'schedule'])->name('api.v1.public.schedule');
        Route::get('/classes', [PublicScheduleController::class, 'classes'])->name('api.v1.public.classes');
    });

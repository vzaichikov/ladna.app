<?php

use App\Http\Controllers\FestivalUploadController;
use App\Http\Middleware\AuthenticateCustomerRememberToken;
use App\Http\Middleware\AuthenticateFestivalUpload;
use App\Http\Middleware\EnsureFestivalEditionWritable;
use App\Http\Middleware\EnsureFestivalsEnabled;
use App\Http\Middleware\EnsurePublicSubscriptionIsActive;
use App\Http\Middleware\PreventReadOnlyDemoMutations;
use App\Http\Middleware\SetLocale;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::domain(parse_url(config('festival_uploads.url'), PHP_URL_HOST) ?: 'upload.ladna.app')
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
        SetLocale::class,
        AuthenticateCustomerRememberToken::class,
    ])
    ->middleware(['signed', SubstituteBindings::class, EnsureFestivalsEnabled::class, AuthenticateFestivalUpload::class, EnsurePublicSubscriptionIsActive::class, EnsureFestivalEditionWritable::class, PreventReadOnlyDemoMutations::class])
    ->post('/festival-uploads/{accountSlug}/entries/{festivalEntry}/requirements/{festivalEntryRequirement}', [FestivalUploadController::class, 'store'])
    ->whereNumber(['festivalEntry', 'festivalEntryRequirement'])
    ->name('festival.uploads.store');

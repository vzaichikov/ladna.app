<?php

use App\Http\Controllers\PublicBookingController;
use App\Http\Controllers\PublicClassPassPurchaseController;
use App\Http\Controllers\PublicEventCheckoutController;
use App\Http\Controllers\PublicEventController;
use App\Http\Controllers\PublicEventEntranceController;
use App\Http\Controllers\PublicPriceController;
use App\Http\Controllers\PublicScheduleController;
use App\Http\Controllers\PublicStudioLandingController;
use App\Http\Controllers\PublicStudioOfferController;
use App\Http\Controllers\PublicStudioRulesController;
use App\Http\Middleware\EnsurePublicSubscriptionIsActive;
use App\Http\Middleware\PreventReadOnlyDemoMutations;
use Illuminate\Support\Facades\Route;

Route::get('/event-checkout/google/callback', [PublicEventCheckoutController::class, 'googleCallback'])
    ->middleware('throttle:30,1')
    ->name('public.event-checkout.google.callback');
Route::get('/{accountSlug}', PublicStudioLandingController::class)
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('public.studio');
Route::get('/{accountSlug}/events', [PublicEventController::class, 'index'])
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('public.events.index');
Route::get('/{accountSlug}/events/{eventSlug}', [PublicEventController::class, 'show'])
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('public.events.show');
Route::get('/{accountSlug}/events/{eventSlug}/entrance', [PublicEventEntranceController::class, 'show'])
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('public.events.entrance');
Route::post('/{accountSlug}/events/{eventSlug}/entrance', [PublicEventEntranceController::class, 'store'])
    ->middleware([PreventReadOnlyDemoMutations::class, EnsurePublicSubscriptionIsActive::class, 'throttle:event-checkout'])
    ->name('public.events.entrance.store');
Route::post('/{accountSlug}/events/{eventSlug}/checkout', [PublicEventCheckoutController::class, 'store'])
    ->middleware([PreventReadOnlyDemoMutations::class, EnsurePublicSubscriptionIsActive::class, 'throttle:event-checkout'])
    ->name('public.events.checkout');
Route::post('/{accountSlug}/events/{eventSlug}/checkout/google', [PublicEventCheckoutController::class, 'google'])
    ->middleware([EnsurePublicSubscriptionIsActive::class, 'throttle:30,1'])
    ->name('public.events.checkout.google');
Route::get('/{accountSlug}/event-orders/{accessToken}', [PublicEventCheckoutController::class, 'order'])
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('public.event-orders.show');
Route::get('/{accountSlug}/event-orders/{accessToken}/payment', [PublicEventCheckoutController::class, 'payment'])
    ->middleware([EnsurePublicSubscriptionIsActive::class, 'throttle:120,1'])
    ->name('public.event-orders.payment');
Route::get('/{accountSlug}/event-orders/{accessToken}/status', [PublicEventCheckoutController::class, 'status'])
    ->middleware([EnsurePublicSubscriptionIsActive::class, 'throttle:120,1'])
    ->name('public.event-orders.status');
Route::get('/{accountSlug}/event-orders/{accessToken}/tickets.pdf', [PublicEventCheckoutController::class, 'pdf'])
    ->middleware([EnsurePublicSubscriptionIsActive::class, 'throttle:120,1'])
    ->name('public.event-orders.pdf');
Route::get('/{accountSlug}/event-orders/{accessToken}/tickets/{ticketCode}/qr', [PublicEventCheckoutController::class, 'ticketQr'])
    ->middleware([EnsurePublicSubscriptionIsActive::class, 'throttle:120,1'])
    ->name('public.event-tickets.qr');
Route::get('/{accountSlug}/rules', PublicStudioRulesController::class)
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('public.studio-rules');
Route::get('/{accountSlug}/offer', PublicStudioOfferController::class)
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('public.studio-offer');
Route::get('/{accountSlug}/{locationSlug}/schedule', [PublicScheduleController::class, 'show'])
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('public.schedule');
Route::get('/{accountSlug}/{locationSlug}/schedule/embed', [PublicScheduleController::class, 'embed'])
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('public.schedule.embed');
Route::get('/{accountSlug}/{locationSlug}/schedule/book', [PublicBookingController::class, 'show'])
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('public.booking.show');
Route::post('/{accountSlug}/{locationSlug}/schedule/book', [PublicBookingController::class, 'store'])
    ->middleware([PreventReadOnlyDemoMutations::class, EnsurePublicSubscriptionIsActive::class, 'throttle:public-booking'])
    ->name('public.booking.store');
Route::get('/{accountSlug}/{locationSlug}/price', [PublicPriceController::class, 'show'])
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('public.price');
Route::get('/{accountSlug}/{locationSlug}/price/embed', [PublicPriceController::class, 'embed'])
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('public.price.embed');
Route::get('/{accountSlug}/{locationSlug}/price/{classPassPlanSlug}/buy', [PublicClassPassPurchaseController::class, 'show'])
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('public.class-pass-plans.buy');
Route::post('/{accountSlug}/{locationSlug}/price/{classPassPlanSlug}/buy', [PublicClassPassPurchaseController::class, 'store'])
    ->middleware([PreventReadOnlyDemoMutations::class, EnsurePublicSubscriptionIsActive::class])
    ->name('public.class-pass-plans.purchase');

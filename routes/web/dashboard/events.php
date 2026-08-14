<?php

use App\Http\Controllers\EventController;
use App\Http\Controllers\EventOrderController;
use App\Http\Controllers\EventScannerController;
use App\Http\Controllers\EventTicketTypeController;
use Illuminate\Support\Facades\Route;

Route::get('accounts/{account}/events', [EventController::class, 'index'])->name('accounts.events.index');
Route::get('accounts/{account}/events/create', [EventController::class, 'create'])->name('accounts.events.create');
Route::post('accounts/{account}/events', [EventController::class, 'store'])->name('accounts.events.store');
Route::get('accounts/{account}/events/{event:slug}/edit', [EventController::class, 'edit'])->scopeBindings()->name('accounts.events.edit');
Route::put('accounts/{account}/events/{event:slug}', [EventController::class, 'update'])->scopeBindings()->name('accounts.events.update');
Route::delete('accounts/{account}/events/{event:slug}', [EventController::class, 'destroy'])->scopeBindings()->name('accounts.events.destroy');
Route::post('accounts/{account}/events/{event:slug}/publish', [EventController::class, 'publish'])->scopeBindings()->name('accounts.events.publish');
Route::post('accounts/{account}/events/{event:slug}/cancel', [EventController::class, 'cancel'])->scopeBindings()->name('accounts.events.cancel');
Route::post('accounts/{account}/events/{event:slug}/archive', [EventController::class, 'archive'])->scopeBindings()->name('accounts.events.archive');
Route::post('accounts/{account}/events/{event:slug}/ticket-types', [EventTicketTypeController::class, 'store'])->scopeBindings()->name('accounts.events.ticket-types.store');
Route::put('accounts/{account}/events/{event:slug}/ticket-types/{eventTicketType}', [EventTicketTypeController::class, 'update'])->scopeBindings()->name('accounts.events.ticket-types.update');
Route::delete('accounts/{account}/events/{event:slug}/ticket-types/{eventTicketType}', [EventTicketTypeController::class, 'destroy'])->scopeBindings()->name('accounts.events.ticket-types.destroy');
Route::get('accounts/{account}/events/{event:slug}/orders', [EventOrderController::class, 'index'])->scopeBindings()->name('accounts.events.orders.index');
Route::post('accounts/{account}/events/{event:slug}/orders/{eventOrder}/resend', [EventOrderController::class, 'resend'])->scopeBindings()->name('accounts.events.orders.resend');
Route::post('accounts/{account}/events/{event:slug}/orders/{eventOrder}/refund', [EventOrderController::class, 'refund'])->scopeBindings()->name('accounts.events.orders.refund');
Route::post('accounts/{account}/events/{event:slug}/orders/{eventOrder}/tickets/{eventTicket}/void', [EventOrderController::class, 'voidTicket'])->scopeBindings()->name('accounts.events.orders.tickets.void');
Route::get('accounts/{account}/events/{event:slug}/scanner', [EventScannerController::class, 'show'])->scopeBindings()->name('accounts.events.scanner');
Route::post('accounts/{account}/events/{event:slug}/scanner/scan', [EventScannerController::class, 'scan'])->middleware('throttle:event-scanner')->scopeBindings()->name('accounts.events.scanner.scan');
Route::post('accounts/{account}/events/{event:slug}/scanner/tickets/{eventTicket}/check-out', [EventScannerController::class, 'checkOut'])->middleware('throttle:event-scanner')->scopeBindings()->name('accounts.events.scanner.check-out');

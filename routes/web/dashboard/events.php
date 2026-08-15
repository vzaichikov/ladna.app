<?php

use App\Http\Controllers\EventController;
use App\Http\Controllers\EventOrderController;
use App\Http\Controllers\EventScannerController;
use App\Http\Controllers\EventTicketController;
use App\Http\Controllers\EventTicketIssuanceController;
use App\Http\Controllers\EventTicketOverviewController;
use App\Http\Controllers\EventTicketTypeController;
use Illuminate\Support\Facades\Route;

Route::get('accounts/{account}/events', [EventController::class, 'index'])->name('accounts.events.index');
Route::get('accounts/{account}/events/create', [EventController::class, 'create'])->name('accounts.events.create');
Route::post('accounts/{account}/events', [EventController::class, 'store'])->name('accounts.events.store');
Route::get('accounts/{account}/events/{event:id}/edit', [EventController::class, 'edit'])->scopeBindings()->name('accounts.events.edit');
Route::put('accounts/{account}/events/{event:id}', [EventController::class, 'update'])->scopeBindings()->name('accounts.events.update');
Route::delete('accounts/{account}/events/{event:id}', [EventController::class, 'destroy'])->scopeBindings()->name('accounts.events.destroy');
Route::post('accounts/{account}/events/{event:id}/publish', [EventController::class, 'publish'])->scopeBindings()->name('accounts.events.publish');
Route::post('accounts/{account}/events/{event:id}/cancel', [EventController::class, 'cancel'])->scopeBindings()->name('accounts.events.cancel');
Route::post('accounts/{account}/events/{event:id}/archive', [EventController::class, 'archive'])->scopeBindings()->name('accounts.events.archive');
Route::get('accounts/{account}/events/{event:id}/ticket-types', [EventTicketTypeController::class, 'index'])->scopeBindings()->name('accounts.events.ticket-types.index');
Route::get('accounts/{account}/events/{event:id}/ticket-types/create', [EventTicketTypeController::class, 'create'])->scopeBindings()->name('accounts.events.ticket-types.create');
Route::post('accounts/{account}/events/{event:id}/ticket-types', [EventTicketTypeController::class, 'store'])->scopeBindings()->name('accounts.events.ticket-types.store');
Route::get('accounts/{account}/events/{event:id}/ticket-types/{eventTicketType}/edit', [EventTicketTypeController::class, 'edit'])->scopeBindings()->name('accounts.events.ticket-types.edit');
Route::put('accounts/{account}/events/{event:id}/ticket-types/{eventTicketType}', [EventTicketTypeController::class, 'update'])->scopeBindings()->name('accounts.events.ticket-types.update');
Route::delete('accounts/{account}/events/{event:id}/ticket-types/{eventTicketType}', [EventTicketTypeController::class, 'destroy'])->scopeBindings()->name('accounts.events.ticket-types.destroy');
Route::get('accounts/{account}/events/{event:id}/tickets', [EventTicketController::class, 'index'])->scopeBindings()->name('accounts.events.tickets.index');
Route::get('accounts/{account}/events/{event:id}/tickets/issue', [EventTicketIssuanceController::class, 'create'])->scopeBindings()->name('accounts.events.tickets.issue.create');
Route::post('accounts/{account}/events/{event:id}/tickets/issue', [EventTicketIssuanceController::class, 'store'])->scopeBindings()->name('accounts.events.tickets.issue.store');
Route::get('accounts/{account}/events/{event:id}/orders', [EventOrderController::class, 'index'])->scopeBindings()->name('accounts.events.orders.index');
Route::post('accounts/{account}/events/{event:id}/orders/{eventOrder}/resend', [EventOrderController::class, 'resend'])->scopeBindings()->name('accounts.events.orders.resend');
Route::post('accounts/{account}/events/{event:id}/orders/{eventOrder}/refund', [EventOrderController::class, 'refund'])->scopeBindings()->name('accounts.events.orders.refund');
Route::post('accounts/{account}/events/{event:id}/orders/{eventOrder}/tickets/{eventTicket}/void', [EventOrderController::class, 'voidTicket'])->scopeBindings()->name('accounts.events.orders.tickets.void');
Route::get('accounts/{account}/events/{event:id}/scanner', [EventScannerController::class, 'show'])->scopeBindings()->name('accounts.events.scanner');
Route::post('accounts/{account}/events/{event:id}/scanner/scan', [EventScannerController::class, 'scan'])->middleware('throttle:event-scanner')->scopeBindings()->name('accounts.events.scanner.scan');
Route::post('accounts/{account}/events/{event:id}/scanner/tickets/{eventTicket}/check-out', [EventScannerController::class, 'checkOut'])->middleware('throttle:event-scanner')->scopeBindings()->name('accounts.events.scanner.check-out');
Route::get('accounts/{account}/events/{event:id}/attendance', [EventTicketOverviewController::class, 'show'])->scopeBindings()->name('accounts.events.attendance');
Route::get('accounts/{account}/events/{event:id}/attendance/data', [EventTicketOverviewController::class, 'data'])->scopeBindings()->name('accounts.events.attendance.data');

<?php

use App\Enums\ScheduleKind;
use App\Http\Controllers\ActivityDirectionController;
use App\Http\Controllers\ClassPassPlanController;
use App\Http\Controllers\ClassPassSegmentController;
use App\Http\Controllers\ClassTypeController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\RoomCameraTestController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomPeopleCounterMaskController;
use App\Http\Controllers\ServiceRoomController;
use App\Http\Controllers\StudioPromoCodeController;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::resource('accounts.locations', LocationController::class)
    ->except(['show'])
    ->scoped();
Route::match(['post', 'put', 'patch'], 'accounts/{account}/rooms/test-camera', RoomCameraTestController::class)
    ->name('accounts.rooms.test-camera');
Route::match(['post', 'put', 'patch'], 'accounts/{account}/service-rooms/test-camera', RoomCameraTestController::class)
    ->name('accounts.service-rooms.test-camera');
Route::resource('accounts.service-rooms', ServiceRoomController::class)
    ->except(['show'])
    ->scoped();
Route::get('accounts/{account}/rooms/{room}/people-counter-mask', [RoomPeopleCounterMaskController::class, 'edit'])
    ->name('accounts.rooms.people-counter-mask.edit');
Route::post('accounts/{account}/rooms/{room}/people-counter-mask/snapshot', [RoomPeopleCounterMaskController::class, 'capture'])
    ->name('accounts.rooms.people-counter-mask.capture');
Route::get('accounts/{account}/rooms/{room}/people-counter-mask/snapshot', [RoomPeopleCounterMaskController::class, 'snapshot'])
    ->name('accounts.rooms.people-counter-mask.snapshot');
Route::put('accounts/{account}/rooms/{room}/people-counter-mask', [RoomPeopleCounterMaskController::class, 'update'])
    ->name('accounts.rooms.people-counter-mask.update');
Route::resource('accounts.rooms', RoomController::class)
    ->except(['show'])
    ->scoped();
Route::post('accounts/{account}/activity-directions/{activity_direction}/copy', [ActivityDirectionController::class, 'copy'])
    ->name('accounts.activity-directions.copy');
Route::resource('accounts.activity-directions', ActivityDirectionController::class)
    ->except(['show'])
    ->scoped();

foreach ([
    ['group-classes', 'group-classes', ScheduleKind::GroupClass],
    ['private-lessons', 'private-lessons', ScheduleKind::PrivateLesson],
    ['room-rentals', 'room-rentals', ScheduleKind::RoomRental],
    ['internal-classes', 'internal-classes', ScheduleKind::InternalClass],
] as [$uri, $name, $scheduleKind]) {
    Route::get("accounts/{account}/{$uri}", [ClassTypeController::class, 'index'])
        ->defaults('schedule_kind', $scheduleKind->value)
        ->name("accounts.{$name}.index");
    Route::get("accounts/{account}/{$uri}/create", [ClassTypeController::class, 'create'])
        ->defaults('schedule_kind', $scheduleKind->value)
        ->name("accounts.{$name}.create");
    Route::post("accounts/{account}/{$uri}", [ClassTypeController::class, 'store'])
        ->defaults('schedule_kind', $scheduleKind->value)
        ->name("accounts.{$name}.store");
    Route::post("accounts/{account}/{$uri}/{class_type}/copy", [ClassTypeController::class, 'copy'])
        ->defaults('schedule_kind', $scheduleKind->value)
        ->name("accounts.{$name}.copy");
    Route::get("accounts/{account}/{$uri}/{class_type}/edit", [ClassTypeController::class, 'edit'])
        ->defaults('schedule_kind', $scheduleKind->value)
        ->name("accounts.{$name}.edit");
    Route::match(['put', 'patch'], "accounts/{account}/{$uri}/{class_type}", [ClassTypeController::class, 'update'])
        ->defaults('schedule_kind', $scheduleKind->value)
        ->name("accounts.{$name}.update");
    Route::delete("accounts/{account}/{$uri}/{class_type}", [ClassTypeController::class, 'destroy'])
        ->defaults('schedule_kind', $scheduleKind->value)
        ->name("accounts.{$name}.destroy");
}

Route::get('accounts/{account}/class-types', fn (Account $account): RedirectResponse => redirect()->route('dashboard.accounts.group-classes.index', $account))
    ->name('accounts.class-types.index');
Route::get('accounts/{account}/class-types/create', fn (Account $account): RedirectResponse => redirect()->route('dashboard.accounts.group-classes.create', $account))
    ->name('accounts.class-types.create');
Route::get('accounts/{account}/class-types/{class_type}/edit', fn (Account $account, int $class_type): RedirectResponse => redirect()->route('dashboard.accounts.group-classes.edit', [$account, $class_type]))
    ->name('accounts.class-types.edit');
Route::post('accounts/{account}/class-types/{class_type}/copy', [ClassTypeController::class, 'copy'])
    ->defaults('schedule_kind', ScheduleKind::GroupClass->value)
    ->name('accounts.class-types.copy');
Route::post('accounts/{account}/class-types', [ClassTypeController::class, 'store'])
    ->defaults('schedule_kind', ScheduleKind::GroupClass->value)
    ->name('accounts.class-types.store');
Route::match(['put', 'patch'], 'accounts/{account}/class-types/{class_type}', [ClassTypeController::class, 'update'])
    ->defaults('schedule_kind', ScheduleKind::GroupClass->value)
    ->name('accounts.class-types.update');
Route::delete('accounts/{account}/class-types/{class_type}', [ClassTypeController::class, 'destroy'])
    ->defaults('schedule_kind', ScheduleKind::GroupClass->value)
    ->name('accounts.class-types.destroy');

Route::patch('accounts/{account}/class-pass-plans/reorder', [ClassPassPlanController::class, 'reorder'])
    ->name('accounts.class-pass-plans.reorder');
Route::post('accounts/{account}/class-pass-plans/{class_pass_plan}/copy', [ClassPassPlanController::class, 'copy'])
    ->name('accounts.class-pass-plans.copy');
Route::resource('accounts.class-pass-plans', ClassPassPlanController::class)
    ->except(['show'])
    ->scoped();
Route::resource('accounts.promo-codes', StudioPromoCodeController::class)
    ->parameters(['promo-codes' => 'studioPromoCode'])
    ->except(['show'])
    ->scoped();
Route::resource('accounts.class-pass-segments', ClassPassSegmentController::class)
    ->except(['show'])
    ->scoped();

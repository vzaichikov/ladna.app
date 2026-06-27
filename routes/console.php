<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('schedule:generate')
    ->daily()
    ->withoutOverlapping();

Schedule::command('class-passes:normalize')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30);

Schedule::command('billing:reconcile')
    ->hourly()
    ->withoutOverlapping(30);

Schedule::command('account-activity-logs:prune')
    ->daily()
    ->withoutOverlapping(30);

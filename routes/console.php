<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Phase 8 — alerts
|--------------------------------------------------------------------------
|
| Hourly rather than daily so a same-day condition (a return due this
| afternoon, an overdue booking) surfaces while it can still be acted on.
| Deduplication is what makes an hourly cadence safe: re-running does not
| re-alert, so the sweep is idempotent.
|
| withoutOverlapping guards a sweep that outruns the hour — otherwise two
| evaluations race and both pass the dedup check before either writes.
|
*/

Schedule::command('alerts:evaluate')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Hourly, but each user is only mailed in the hour matching their configured
// digest time.
Schedule::command('alerts:digest')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

/*

|--------------------------------------------------------------------------
| Phase 9b — saved scheduled reports
|--------------------------------------------------------------------------
|
| Every minute we check which saved report definitions are due and dispatch
| their export jobs. ExportJob emails the completed file on success.
| withoutOverlapping is necessary because a report that takes >1 min to
| dispatch could otherwise fire twice in the same minute.
|
*/

Schedule::command('reports:run-scheduled')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

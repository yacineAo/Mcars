<?php

declare(strict_types=1);

use App\Services\BackupService;
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
| Phase 10 — backups
|--------------------------------------------------------------------------
|
| Nightly database dump, weekly full including media. The cleanup strategy
| in config/backup.php determines retention.
|
*/

Schedule::command('backup:run --only-db')
    ->daily()->at('02:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('backup:run')
    ->weekly()->sundays()->at('03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('backup:clean')
    ->daily()->at('04:00')
    ->withoutOverlapping()
    ->onOneServer();

// Purge activity log entries older than the configured retention (365 days).
Schedule::command('activitylog:clean')
    ->daily()->at('05:00')
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Phase 10 — backup verification
|--------------------------------------------------------------------------
|
| A backup that has never been restored is only a hypothesis. Weekly
| verification restores the latest backup into a scratch database and
| asserts every table's row count matches the production database.
|
| Runs after the weekly full backup on Sunday morning. Must NOT overlap
| with backup:run or backup:clean on the same server.
|
*/

Schedule::call(fn () => app(BackupService::class)->verifyLatest())
    ->name('backup-verify')
    ->weekly()->sundays()->at('05:00')
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

<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TransactionPosted;
use App\Services\ReportService;

/**
 * Every posting invalidates the cached reports for its branch and the global roll-up.
 *
 * Report cache keys are qualified by date, month and branch, so they cannot be
 * enumerated and forgotten individually — ReportService::flushCache() bumps a
 * per-scope version stamp that orphans them all at once.
 */
class FlushReportCache
{
    public function handle(TransactionPosted $event): void
    {
        ReportService::flushCache($event->transaction->branch_id);
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ReportDefinition;
use App\Services\Reporting\ScheduledReportRunner;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Command;

/**
 * Every-minute sweep that dispatches queued export jobs for saved report
 * definitions whose cron expression matches the current minute.
 *
 * Each dispatched export carries a `report_definition_id` so ExportJob can
 * send the completed file to the definition's schedule recipients. The run
 * itself is built by ScheduledReportRunner — the same code the "Run now"
 * action calls, so manual and scheduled runs are the same thing.
 */
class RunScheduledReports extends Command
{
    protected $signature = 'reports:run-scheduled
        {--now= : Run as if it were this moment, e.g. 2026-09-01 08:00}';

    protected $description = 'Evaluate scheduled report definitions and dispatch any that are due';

    public function __construct(
        private readonly ScheduledReportRunner $runner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = $this->resolveNow();

        if ($now === null) {
            return self::FAILURE;
        }

        $definitions = ReportDefinition::query()
            ->where('schedule_enabled', true)
            ->whereNotNull('schedule_cron')
            ->whereNotNull('schedule_email')
            ->get();

        $count = 0;

        foreach ($definitions as $definition) {
            if (! $this->isDue($definition, $now)) {
                continue;
            }

            $this->runner->run($definition, $now);

            $count++;
        }

        $this->info("Dispatched {$count} scheduled report(s).");

        return self::SUCCESS;
    }

    private function isDue(ReportDefinition $definition, CarbonImmutable $now): bool
    {
        $cron = $definition->schedule_cron;

        if ($cron === null) {
            return false;
        }

        return (new CronExpression($cron))->isDue($now->toDateTime());
    }

    private function resolveNow(): ?CarbonImmutable
    {
        $now = $this->option('now');

        if ($now === null) {
            return CarbonImmutable::now();
        }

        try {
            return CarbonImmutable::parse((string) $now);
        } catch (\Throwable) {
            $this->error("Could not parse --now='{$now}'.");

            return null;
        }
    }
}

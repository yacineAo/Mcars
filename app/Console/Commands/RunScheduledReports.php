<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ExportJob;
use App\Models\PendingExport;
use App\Models\ReportDefinition;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;

/**
 * Every-minute sweep that dispatches queued export jobs for saved report
 * definitions whose cron expression matches the current minute.
 *
 * Each dispatched export carries a `report_definition_id` so ExportJob can
 * send the completed file to the definition's `schedule_email` recipient.
 */
class RunScheduledReports extends Command
{
    protected $signature = 'reports:run-scheduled
        {--now= : Run as if it were this moment, e.g. 2026-09-01 08:00}';

    protected $description = 'Evaluate scheduled report definitions and dispatch any that are due';

    public function __construct(
        private readonly DatabaseManager $db,
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

            $this->db->transaction(function () use ($definition, $now, &$count): void {
                $parameters = $definition->parameters;

                $parameters['from'] = $now->subMonth()->startOfMonth()->format('Y-m-d');
                $parameters['to'] = $now->subMonth()->endOfMonth()->format('Y-m-d');

                if ($definition->branch_id !== null) {
                    $parameters['branch_id'] = $definition->branch_id;
                }

                /** @var PendingExport $pendingExport */
                $pendingExport = PendingExport::create([
                    'branch_id' => $definition->branch_id,
                    'user_id' => $definition->user_id,
                    'report_definition_id' => $definition->id,
                    'report_type' => $definition->report_type,
                    'format' => $definition->format,
                    'parameters' => $parameters,
                    'status' => 'pending',
                ]);

                ExportJob::dispatch($pendingExport, $definition->user_id);

                $definition->update(['last_sent_at' => $now]);

                $count++;
            });
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

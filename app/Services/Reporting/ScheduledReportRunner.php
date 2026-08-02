<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Jobs\ExportJob;
use App\Models\PendingExport;
use App\Models\ReportDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Turns a saved report definition into a run.
 *
 * The single place that decides what a run looks like: the definition's
 * parameters, the last completed month as the window, the branch pinned into
 * the parameters, the pending export row and the dispatched job. Both the
 * cron sweep (RunScheduledReports) and the "Run now" action on the resource
 * call this, so a manual run and a scheduled run can never disagree about
 * what a run is.
 */
final class ScheduledReportRunner
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function run(ReportDefinition $definition, CarbonImmutable $now): PendingExport
    {
        return $this->db->transaction(function () use ($definition, $now): PendingExport {
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

            return $pendingExport;
        });
    }
}

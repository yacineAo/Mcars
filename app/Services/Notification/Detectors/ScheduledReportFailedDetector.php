<?php

declare(strict_types=1);

namespace App\Services\Notification\Detectors;

use App\Enums\AlertType;
use App\Models\AlertRule;
use App\Models\PendingExport;
use App\Models\ReportDefinition;
use App\Services\Notification\AlertSubject;
use App\Services\Notification\Contracts\AlertDetector;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Saved reports whose last scheduled run failed.
 *
 * A report someone relies on monthly fails silently today: ExportJob marks the
 * run failed and the email simply does not arrive. This detector reads the run
 * history — the one source that knows whether the report worked — and raises a
 * subject per definition that has at least one failed run. Deduplication then
 * paces the repeat: the rule's repeat dial decides how often the office is
 * reminded that the report is still broken.
 */
final class ScheduledReportFailedDetector implements AlertDetector
{
    public function type(): AlertType
    {
        return AlertType::ScheduledReportFailed;
    }

    public function detect(AlertRule $rule, CarbonImmutable $now): iterable
    {
        $query = ReportDefinition::query()
            ->whereHas(
                'pendingExports',
                fn (Builder $q): Builder => $q->where('status', 'failed'),
            )
            ->with([
                'pendingExports' => fn ($q) => $q->where('status', 'failed')->latest('failed_at')->limit(1),
            ]);

        if ($rule->branch_id !== null) {
            $query->where('branch_id', $rule->branch_id);
        }

        foreach ($query->lazyById(100) as $definition) {
            /** @var PendingExport|null $latestFailedRun */
            $latestFailedRun = $definition->pendingExports->first();

            /** @var CarbonImmutable|null $failedAt */
            $failedAt = $latestFailedRun?->failed_at;

            yield new AlertSubject(
                subject: $definition,
                branchId: $definition->branch_id,
                payload: [
                    'name' => $definition->name,
                    'failed_at' => $failedAt?->translatedFormat('d/m/Y H:i') ?? '—',
                ],
            );
        }
    }
}

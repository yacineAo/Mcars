<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NotificationStatus;
use App\Models\NotificationLog;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scheduled retention retire for the delivery audit trail.
 *
 * ADR-012 only ever needs history as far back as the longest repeat window of
 * an active rule, so a delivery row can never suppress an alert again once it
 * is both terminal and older than that horizon. A scheduled prune is therefore
 * compatible with correctness in a way an ad-hoc UI delete never is.
 *
 * Only terminal rows are removed (anything past Queued/Sending). A row still in
 * Queued or Sending is one whose dedup window is still open — deleting it would
 * silently reopen the claim for a recipient about to receive the message.
 */
class AlertsPruneLogs extends Command
{
    protected $signature = 'alerts:prune-logs {--days=365 : Delete terminal deliveries older than this many days}';

    protected $description = 'Prune terminal notification_logs older than the retention horizon';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));

        $deleted = NotificationLog::query()
            ->where('created_at', '<', now()->subDays($days))
            ->where(function (Builder $query): Builder {
                return $query->whereNotIn('status', [
                    NotificationStatus::Queued->value,
                    NotificationStatus::Sending->value,
                ]);
            })
            ->delete();

        $this->info("Pruned {$deleted} terminal deliveries older than {$days} days.");

        return self::SUCCESS;
    }
}

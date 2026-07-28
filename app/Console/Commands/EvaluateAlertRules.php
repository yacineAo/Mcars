<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AlertType;
use App\Models\AlertRule;
use App\Services\Notification\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The hourly sweep.
 *
 * Evaluates every active alert rule and queues whatever is due. Idempotent by
 * design: running it twice in an hour queues nothing extra, because deduplication
 * is decided per subject, not per run.
 */
class EvaluateAlertRules extends Command
{
    protected $signature = 'alerts:evaluate
        {--type= : Evaluate only this alert type}
        {--now= : Evaluate as if it were this moment, e.g. 2026-08-02 07:00}';

    protected $description = 'Evaluate alert rules and queue any notifications that are due';

    public function handle(NotificationService $notifications): int
    {
        $now = $this->resolveNow();

        if ($now === null) {
            return self::FAILURE;
        }

        $type = $this->option('type');

        if ($type === null) {
            $queued = $notifications->evaluateAll($now);
            $this->info("Queued {$queued} notification(s).");

            return self::SUCCESS;
        }

        $alertType = AlertType::tryFrom((string) $type);

        if ($alertType === null) {
            $this->error("Unknown alert type '{$type}'. Known: ".implode(', ', AlertType::values()));

            return self::FAILURE;
        }

        $queued = 0;

        foreach (AlertRule::query()->active()->ofType($alertType)->get() as $rule) {
            $queued += $notifications->evaluate($rule, $now);
        }

        $this->info("Queued {$queued} notification(s) for {$alertType->value}.");

        return self::SUCCESS;
    }

    /** Null signals an unparseable --now, which is an error rather than "use today". */
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

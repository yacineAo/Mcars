<?php

declare(strict_types=1);

namespace App\Services\Notification\Detectors;

use App\Enums\AlertType;
use App\Models\AlertRule;
use App\Services\Notification\Contracts\AlertDetector;
use Carbon\CarbonImmutable;

/**
 * Backup failures.
 *
 * Deliberately finds nothing. Backups arrive in Phase 10 (ADV-08) and there is no
 * table to poll yet — inventing one here would mean Phase 10 either adopts a guess
 * or migrates away from it.
 *
 * A backup failure is also the wrong shape for polling: it is an event that
 * happens once, not a state that persists. When Phase 10 lands, the backup job
 * should raise the alert directly through
 * NotificationService::alertOnce($rule, $subject) rather than gaining a detector
 * here, and this class should be deleted along with the AlertType's registry entry.
 *
 * The rule is still seeded and manageable in the UI so the channel/recipient
 * configuration is ready when the emitter exists.
 */
final class BackupFailedDetector implements AlertDetector
{
    public function type(): AlertType
    {
        return AlertType::BackupFailed;
    }

    public function detect(AlertRule $rule, CarbonImmutable $now): iterable
    {
        return [];
    }
}

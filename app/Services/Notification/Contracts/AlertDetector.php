<?php

declare(strict_types=1);

namespace App\Services\Notification\Contracts;

use App\Enums\AlertType;
use App\Models\AlertRule;
use App\Services\Notification\AlertSubject;
use Carbon\CarbonImmutable;

/**
 * Finds the subjects a given alert type currently applies to.
 *
 * A detector answers "what is due?" and nothing else — it does not know about
 * channels, recipients or deduplication. That keeps the lead-time query, which is
 * the part worth testing precisely, isolated and cheap to test.
 */
interface AlertDetector
{
    public function type(): AlertType;

    /**
     * Subjects that satisfy the rule's lead time as of $now.
     *
     * Must respect $rule->days_before exactly: a subject further out than the lead
     * time must not be returned, or the alert fires early.
     *
     * @return iterable<AlertSubject>
     */
    public function detect(AlertRule $rule, CarbonImmutable $now): iterable;
}

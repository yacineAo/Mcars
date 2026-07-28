<?php

declare(strict_types=1);

namespace App\Services\Notification;

use Illuminate\Database\Eloquent\Model;

/**
 * One thing worth alerting about, found by a detector.
 *
 * Carries no recipient information. Every alert goes to staff, resolved from the
 * rule's roles and the subject's branch — the system has no customer or owner
 * logins for an alert to be addressed to.
 */
final readonly class AlertSubject
{
    /** @param array<string, mixed> $payload template variables */
    public function __construct(
        public Model $subject,
        public ?int $branchId = null,
        public array $payload = [],
    ) {}

    /** Stable identity of the subject, used for deduplication. */
    public function morphType(): string
    {
        return $this->subject->getMorphClass();
    }

    public function morphId(): int|string
    {
        return $this->subject->getKey();
    }
}

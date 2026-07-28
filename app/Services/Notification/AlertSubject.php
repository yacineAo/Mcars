<?php

declare(strict_types=1);

namespace App\Services\Notification;

use Illuminate\Database\Eloquent\Model;

/**
 * One thing worth alerting about, found by a detector.
 *
 * `targetedUserIds` is the safety mechanism behind "an owner alert never reaches
 * another owner": for portal roles the recipient set is intersected with this
 * list, so a rule naming the car_owner role resolves to *this car's* owner rather
 * than every owner in the system.
 */
final readonly class AlertSubject
{
    /**
     * @param array<string, mixed> $payload template variables
     * @param list<int> $targetedUserIds users tied to this subject specifically
     */
    public function __construct(
        public Model $subject,
        public ?int $branchId = null,
        public array $payload = [],
        public array $targetedUserIds = [],
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

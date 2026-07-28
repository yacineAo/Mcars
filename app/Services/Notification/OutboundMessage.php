<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Enums\AlertType;
use App\Models\NotificationLog;
use App\Models\User;

/**
 * A rendered message, ready for any driver.
 *
 * Rendering happens once, before the channel fan-out, so the same alert reads
 * identically by mail and on Discord — and so a driver never has to know what an
 * AlertRule is.
 */
final readonly class OutboundMessage
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public NotificationLog $log,
        public AlertType $alertType,
        public string $subject,
        public string $body,
        public string $recipient,
        public string $locale,
        public array $payload = [],
        public ?User $user = null,
        public ?string $url = null,
    ) {}
}

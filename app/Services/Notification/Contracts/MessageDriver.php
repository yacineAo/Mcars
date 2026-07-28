<?php

declare(strict_types=1);

namespace App\Services\Notification\Contracts;

use App\Enums\NotificationChannel;
use App\Services\Notification\DeliveryResult;
use App\Services\Notification\Exceptions\MessageDeliveryException;
use App\Services\Notification\OutboundMessage;

/**
 * One delivery channel.
 *
 * The whole point of this contract is that the provider behind a channel can be
 * replaced without touching NotificationService, the alert rules, or any caller.
 * Drivers are resolved from config/notifications.php.
 */
interface MessageDriver
{
    public function channel(): NotificationChannel;

    /**
     * Whether the driver is configured well enough to attempt a send.
     *
     * A driver that is off is skipped and its log row cancelled — it is not an
     * error, and it must not consume a retry.
     */
    public function isEnabled(): bool;

    /**
     * @throws MessageDeliveryException when the provider rejects or is unreachable.
     */
    public function send(OutboundMessage $message): DeliveryResult;
}

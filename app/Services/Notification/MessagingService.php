<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\NotificationLog;
use App\Services\Notification\Contracts\MessageDriver;
use App\Services\Notification\Exceptions\MessageDeliveryException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The delivery boundary.
 *
 * Everything above this class talks about channels; only this class knows which
 * concrete provider sits behind one. Swapping Discord for something else is an
 * edit to config/notifications.php.
 *
 * Never called from a request. NotificationService writes the log row, the queue
 * picks it up, and SendNotificationJob calls deliver() — so a provider timeout
 * stalls a worker, never a receptionist mid-checkout.
 */
class MessagingService
{
    /** @var array<string, MessageDriver> */
    private array $resolved = [];

    public function __construct(
        private readonly Container $container,
        private readonly MessageRenderer $renderer,
    ) {}

    /**
     * Deliver one logged notification.
     *
     * @throws MessageDeliveryException so the queue can retry.
     */
    public function deliver(NotificationLog $log): void
    {
        $driver = $this->driverFor($log->channel);

        if (! $driver->isEnabled()) {
            // Not a failure: the channel is switched off. Cancel rather than fail,
            // so it neither burns retries nor shows up as a delivery problem.
            $log->forceFill([
                'status' => NotificationStatus::Cancelled,
                'error' => "Channel {$log->channel->value} is not enabled.",
            ])->save();

            return;
        }

        $rule = $log->alertRule;
        $alertType = $rule?->type;

        if ($alertType === null) {
            // The rule was deleted between queueing and sending. Nothing sensible
            // left to render, and retrying will not bring it back.
            $log->forceFill([
                'status' => NotificationStatus::Cancelled,
                'error' => 'The originating alert rule no longer exists.',
            ])->save();

            return;
        }

        $log->markSending();

        $message = $this->renderer->render($log, $alertType, $log->user);

        try {
            $result = $driver->send($message);
        } catch (MessageDeliveryException $e) {
            // Record the reason now, while we have it. A non-retryable failure is
            // terminal immediately; a retryable one is re-thrown so the queue can
            // back off, and the job's failed() hook finalises it.
            $log->markFailed($e->getMessage());

            throw $e;
        }

        $log->markSent($result->provider, $result->providerMessageId, ['cost' => $result->cost]);

        if ($result->confirmedDelivered) {
            $log->markDelivered();
        }
    }

    /**
     * Resolve the driver for a channel, memoised per request/worker.
     *
     * @throws RuntimeException when a channel has no registered driver. The CHECK
     *                          constraint on notification_logs.channel should make
     *                          this unreachable, but a misedited config would
     *                          otherwise fail silently.
     */
    public function driverFor(NotificationChannel $channel): MessageDriver
    {
        if (isset($this->resolved[$channel->value])) {
            return $this->resolved[$channel->value];
        }

        $class = config("notifications.drivers.{$channel->value}");

        if (! is_string($class) || ! class_exists($class)) {
            throw new RuntimeException(
                "No driver registered for the {$channel->value} channel. ".
                'Add one to config/notifications.php.',
            );
        }

        $driver = $this->container->make($class);

        if (! $driver instanceof MessageDriver) {
            throw new RuntimeException("{$class} must implement ".MessageDriver::class);
        }

        return $this->resolved[$channel->value] = $driver;
    }

    /**
     * Channels with a driver that is currently enabled.
     *
     * Used by the rule form so a manager is not offered a channel that cannot
     * actually deliver.
     *
     * @return list<NotificationChannel>
     */
    public function enabledChannels(): array
    {
        $enabled = [];

        foreach (NotificationChannel::cases() as $channel) {
            try {
                if ($this->driverFor($channel)->isEnabled()) {
                    $enabled[] = $channel;
                }
            } catch (RuntimeException $e) {
                Log::warning('Notification channel has no usable driver.', [
                    'channel' => $channel->value,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        return $enabled;
    }
}

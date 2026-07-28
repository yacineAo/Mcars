<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationStatus;
use App\Models\NotificationLog;
use App\Services\Notification\Exceptions\MessageDeliveryException;
use App\Services\Notification\MessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one logged notification.
 *
 * Every send goes through here — nothing in a request path ever talks to a
 * provider, so a Discord timeout cannot stall a receptionist mid-checkout.
 *
 * Carries the log id rather than the model: the row is mutated during delivery,
 * and a serialised model would restore stale attributes on retry.
 */
class SendNotificationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $notificationLogId)
    {
        $this->onQueue(config('notifications.queue.name', 'notifications'));

        $connection = config('notifications.queue.connection');

        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection);
        }
    }

    public function tries(): int
    {
        return (int) config('notifications.queue.tries', 3);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        $backoff = config('notifications.queue.backoff', [60, 300, 900]);

        return is_array($backoff) ? array_values(array_map('intval', $backoff)) : [60, 300, 900];
    }

    public function handle(MessagingService $messaging): void
    {
        $log = NotificationLog::find($this->notificationLogId);

        if ($log === null) {
            // The row was deleted between queueing and running. Nothing to send and
            // nothing to record.
            return;
        }

        if ($log->status->isTerminal()) {
            return;
        }

        try {
            $messaging->deliver($log);
        } catch (MessageDeliveryException $e) {
            // deliver() has already written the reason to the log. A permanent
            // rejection must not consume the remaining attempts — the same payload
            // will be refused identically every time.
            if (! $e->retryable) {
                Log::warning('Notification permanently rejected; not retrying.', [
                    'notification_log_id' => $log->id,
                    'channel' => $log->channel->value,
                    'reason' => $e->getMessage(),
                ]);

                $this->fail($e);

                return;
            }

            throw $e;
        }
    }

    /**
     * Last attempt has failed, or the job was released for the final time.
     *
     * deliver() records the specific provider error; this guarantees the row ends
     * up Failed even when the job died for another reason — a worker timeout, say,
     * which never reaches the catch above.
     */
    public function failed(?Throwable $e): void
    {
        $log = NotificationLog::find($this->notificationLogId);

        if ($log === null || $log->status === NotificationStatus::Failed) {
            return;
        }

        $log->markFailed($e?->getMessage() ?? 'The delivery job failed without an exception.');
    }
}

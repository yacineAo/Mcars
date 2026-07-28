<?php

declare(strict_types=1);

namespace App\Services\Notification\Exceptions;

use RuntimeException;

/**
 * A send that failed at the provider.
 *
 * Thrown, not returned, so the queue's retry machinery sees it. `$retryable`
 * distinguishes a timeout worth another attempt from a 400 that will fail
 * identically forever — retrying the latter just burns the queue.
 */
class MessageDeliveryException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = true,
        public readonly ?int $statusCode = null,
    ) {
        parent::__construct($message);
    }

    public static function unreachable(string $provider, string $reason): self
    {
        return new self("{$provider} unreachable: {$reason}", retryable: true);
    }

    public static function rejected(string $provider, int $status, string $body): self
    {
        // 4xx other than 429 means the request itself is wrong — the same payload
        // will be rejected on every retry.
        $retryable = $status === 429 || $status >= 500;

        return new self(
            "{$provider} rejected the message (HTTP {$status}): ".mb_substr($body, 0, 500),
            retryable: $retryable,
            statusCode: $status,
        );
    }
}

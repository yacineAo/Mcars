<?php

declare(strict_types=1);

namespace App\Services\Notification;

/**
 * What a driver hands back once the provider has accepted the message.
 *
 * Acceptance is not delivery. `sent` means the provider took it; the log only
 * moves to Delivered when a webhook or a driver that can confirm says so.
 */
final readonly class DeliveryResult
{
    public function __construct(
        public string $provider,
        public ?string $providerMessageId = null,
        public string $cost = '0.00',
        public bool $confirmedDelivered = false,
    ) {}

    /** For local channels that land the moment they are written. */
    public static function localised(string $provider, ?string $id = null): self
    {
        return new self(
            provider: $provider,
            providerMessageId: $id,
            confirmedDelivered: true,
        );
    }
}

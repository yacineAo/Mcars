<?php

declare(strict_types=1);

namespace App\Services\Notification\Drivers;

use App\Enums\NotificationChannel;
use App\Services\Notification\Contracts\MessageDriver;
use App\Services\Notification\DeliveryResult;
use App\Services\Notification\Exceptions\MessageDeliveryException;
use App\Services\Notification\OutboundMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Discord incoming webhook.
 *
 * An alert type may route to its own webhook (finance, workshop, ops) via
 * config('notifications.discord.webhooks'), falling back to the default one.
 */
final class DiscordDriver implements MessageDriver
{
    /** Discord rejects embed descriptions longer than this. */
    private const int MAX_DESCRIPTION = 4096;

    private const int MAX_TITLE = 256;

    public function __construct(private readonly HttpFactory $http) {}

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Discord;
    }

    public function isEnabled(): bool
    {
        return (bool) config('notifications.discord.enabled')
            && $this->defaultWebhook() !== null;
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        $webhook = $this->webhookFor($message);

        if ($webhook === null) {
            throw new MessageDeliveryException(
                'No Discord webhook configured for '.$message->alertType->value,
                retryable: false,
            );
        }

        try {
            $response = $this->http
                ->timeout((int) config('notifications.discord.timeout', 10))
                ->asJson()
                ->post($webhook, $this->payloadFor($message));
        } catch (ConnectionException $e) {
            throw MessageDeliveryException::unreachable('discord', $e->getMessage());
        }

        if ($response->failed()) {
            throw MessageDeliveryException::rejected('discord', $response->status(), $response->body());
        }

        // Discord returns 204 with an empty body unless ?wait=true is used, so
        // there is usually no message id to record.
        return new DeliveryResult(
            provider: 'discord',
            providerMessageId: $response->json('id'),
        );
    }

    /** @return array<string, mixed> */
    private function payloadFor(OutboundMessage $message): array
    {
        $embed = [
            'title' => mb_substr($message->subject, 0, self::MAX_TITLE),
            'description' => mb_substr($message->body, 0, self::MAX_DESCRIPTION),
            'color' => $this->colourFor($message),
            'fields' => $this->fieldsFor($message),
            'footer' => ['text' => config('app.name').' · '.$message->alertType->getLabel()],
        ];

        if ($message->url !== null) {
            $embed['url'] = $message->url;
        }

        return [
            'username' => config('notifications.discord.username', 'Mcars'),
            'embeds' => [$embed],
        ];
    }

    /**
     * Payload scalars become embed fields, so the alert is scannable without
     * opening the app. Nested values are skipped rather than JSON-dumped into a
     * field nobody can read.
     *
     * @return list<array<string, mixed>>
     */
    private function fieldsFor(OutboundMessage $message): array
    {
        $fields = [];

        foreach ($message->payload as $key => $value) {
            if (count($fields) >= 10) {
                break;
            }

            if (! is_scalar($value) || $value === '' || is_bool($value)) {
                continue;
            }

            $fields[] = [
                'name' => mb_substr(__('notifications.fields.'.$key), 0, 256),
                'value' => mb_substr((string) $value, 0, 1024),
                'inline' => true,
            ];
        }

        return $fields;
    }

    /** Discord wants a decimal colour int, so the enum's Filament colour is mapped. */
    private function colourFor(OutboundMessage $message): int
    {
        return match ($message->alertType->getColor()) {
            'danger' => 0xDC2626,
            'warning' => 0xF59E0B,
            'info' => 0x3B82F6,
            default => 0x6B7280,
        };
    }

    private function webhookFor(OutboundMessage $message): ?string
    {
        $overrides = config('notifications.discord.webhooks', []);

        return $overrides[$message->alertType->value] ?? $this->defaultWebhook();
    }

    private function defaultWebhook(): ?string
    {
        $url = config('notifications.discord.webhook_url');

        return is_string($url) && $url !== '' ? $url : null;
    }
}

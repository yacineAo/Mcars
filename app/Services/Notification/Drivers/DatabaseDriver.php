<?php

declare(strict_types=1);

namespace App\Services\Notification\Drivers;

use App\Enums\NotificationChannel;
use App\Services\Notification\Contracts\MessageDriver;
use App\Services\Notification\DeliveryResult;
use App\Services\Notification\Exceptions\MessageDeliveryException;
use App\Services\Notification\OutboundMessage;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;

/**
 * The in-app bell.
 *
 * Writes a Filament database notification against the recipient user, so it shows
 * on whichever panel that user can reach — the bell filters by `notifiable`, which
 * is what keeps one owner from seeing another owner's alerts.
 */
final class DatabaseDriver implements MessageDriver
{
    public function channel(): NotificationChannel
    {
        return NotificationChannel::Database;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        // An in-app notification has nowhere to go without a user row. This is a
        // configuration mistake (a rule targeting a non-user recipient), not a
        // transient fault, so it must not be retried.
        if ($message->user === null) {
            throw new MessageDeliveryException(
                'The database channel requires a user recipient; none was resolved.',
                retryable: false,
            );
        }

        $notification = FilamentNotification::make()
            ->title($message->subject)
            ->body($message->body)
            ->icon($message->alertType->getIcon())
            ->color($message->alertType->getColor());

        if ($message->url !== null) {
            $notification->actions([
                Action::make('view')
                    ->label(__('notifications.actions.view'))
                    ->url($message->url)
                    ->markAsRead(),
            ]);
        }

        $notification->sendToDatabase($message->user);

        return DeliveryResult::localised('database');
    }
}

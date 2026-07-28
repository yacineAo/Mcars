<?php

declare(strict_types=1);

namespace App\Services\Notification\Drivers;

use App\Enums\NotificationChannel;
use App\Mail\AlertMail;
use App\Services\Notification\Contracts\MessageDriver;
use App\Services\Notification\DeliveryResult;
use App\Services\Notification\Exceptions\MessageDeliveryException;
use App\Services\Notification\OutboundMessage;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Email delivery.
 *
 * The mailable renders RTL when the recipient's locale is Arabic — see
 * resources/views/mail/alert.blade.php.
 */
final class MailDriver implements MessageDriver
{
    public function __construct(private readonly MailFactory $mailer) {}

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Mail;
    }

    public function isEnabled(): bool
    {
        return config('mail.default') !== null;
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        if (! filter_var($message->recipient, FILTER_VALIDATE_EMAIL)) {
            throw new MessageDeliveryException(
                "Not a valid email address: {$message->recipient}",
                retryable: false,
            );
        }

        try {
            $this->mailer->mailer()
                ->to($message->recipient)
                ->send(new AlertMail($message));
        } catch (TransportExceptionInterface $e) {
            // SMTP problems are usually transient — a full mailbox or a greylist
            // clears. Let the queue try again.
            throw MessageDeliveryException::unreachable('mail', $e->getMessage());
        }

        return new DeliveryResult(provider: config('mail.default') ?? 'mail');
    }
}

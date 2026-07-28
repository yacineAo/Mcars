<?php

declare(strict_types=1);

namespace App\Mail;

use App\Services\Notification\OutboundMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The alert email.
 *
 * Not queued itself — the whole send already runs inside SendNotificationJob, and
 * queueing a mailable from inside a job would just add a second hop.
 */
class AlertMail extends Mailable
{
    public function __construct(public readonly OutboundMessage $message) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->message->subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.alert',
            with: [
                'alert' => $this->message,
                'isRtl' => $this->message->locale === 'ar',
            ],
        );
    }
}

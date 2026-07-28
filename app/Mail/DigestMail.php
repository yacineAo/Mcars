<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\NotificationLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/**
 * One email summarising a day of alerts for a digest subscriber.
 */
class DigestMail extends Mailable
{
    /** @param Collection<int, NotificationLog> $entries */
    public function __construct(
        public readonly User $user,
        public readonly Collection $entries,
        public readonly CarbonImmutable $since,
        public readonly CarbonImmutable $until,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('notifications.digest.subject', [
                'count' => $this->entries->count(),
                'date' => $this->until->translatedFormat('d/m/Y'),
            ], $this->user->locale ?? 'fr'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.digest',
            with: [
                'user' => $this->user,
                'entries' => $this->entries,
                'isRtl' => ($this->user->locale ?? 'fr') === 'ar',
            ],
        );
    }
}

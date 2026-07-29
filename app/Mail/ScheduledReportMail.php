<?php

declare(strict_types=1);

namespace App\Mail;

use Carbon\CarbonImmutable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ScheduledReportMail extends Mailable
{
    public function __construct(
        public readonly string $reportName,
        public readonly string $fileName,
        private readonly string $content,
        private readonly string $mimeType,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('reports.scheduled_mail.subject', ['name' => $this->reportName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.scheduled-report',
            with: [
                'reportName' => $this->reportName,
                'generatedAt' => CarbonImmutable::now(),
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn (): string => $this->content,
                $this->fileName,
            )->withMime($this->mimeType),
        ];
    }
}

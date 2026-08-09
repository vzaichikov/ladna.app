<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FestivalPortalMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param array<int, string> $lines */
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $greeting,
        public readonly array $lines,
        public readonly ?string $actionLabel = null,
        public readonly ?string $actionUrl = null,
        public readonly string $messageLocale = 'uk',
    ) {
        $this->locale($messageLocale);
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.festival-portal');
    }
}

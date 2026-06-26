<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TransactionalMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $subjectParameters
     */
    public function __construct(
        public readonly string $subjectKey,
        public readonly string $contentView,
        public readonly array $data,
        public readonly array $subjectParameters = [],
    ) {
        $this->afterCommit();
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __($this->subjectKey, $this->subjectParameters),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.transactional',
            with: [
                'contentView' => $this->contentView,
                'data' => $this->data,
                'accountName' => $this->data['account_name'] ?? config('app.name'),
                'accountLogoUrl' => $this->data['account_logo_url'] ?? null,
                'accountBrandColor' => $this->data['account_brand_color'] ?? '#6d28d9',
                'supportUrl' => $this->data['support_url'] ?? null,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}

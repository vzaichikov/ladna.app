<?php

namespace App\Mail;

use App\Support\Mail\EmailDeliveryRecorder;
use App\Support\Mail\TrackedMailTransport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Throwable;

class TransactionalMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public ?int $emailDeliveryId = null;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $subjectParameters
     * @param  array<int, array{name: string, mime: string, data: string}>  $attachmentData
     */
    public function __construct(
        public readonly string $subjectKey,
        public readonly string $contentView,
        public readonly array $data,
        public readonly array $subjectParameters = [],
        public readonly array $attachmentData = [],
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

    public function headers(): Headers
    {
        return new Headers(
            text: $this->emailDeliveryId
                ? [TrackedMailTransport::DeliveryIdHeader => (string) $this->emailDeliveryId]
                : [],
        );
    }

    public function forEmailDelivery(int $deliveryId): static
    {
        $this->emailDeliveryId = $deliveryId;

        return $this;
    }

    public function failed(Throwable $exception): void
    {
        if (! $this->emailDeliveryId) {
            return;
        }

        try {
            app(EmailDeliveryRecorder::class)->markFailed($this->emailDeliveryId, $exception);
        } catch (Throwable $auditException) {
            report($auditException);
        }
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return collect($this->attachmentData)
            ->map(fn (array $attachment): Attachment => Attachment::fromData(
                fn (): string => base64_decode($attachment['data'], true) ?: '',
                $attachment['name'],
            )->withMime($attachment['mime']))
            ->all();
    }
}

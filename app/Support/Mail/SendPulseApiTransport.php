<?php

namespace App\Support\Mail;

use App\Support\SendPulse\SendPulseApiClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Throwable;

class SendPulseApiTransport extends AbstractTransport
{
    public function __construct(
        private readonly SendPulseApiClient $client,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        try {
            $response = $this->client->post('/smtp/emails', [
                'email' => $this->payload($message),
            ]);
        } catch (Throwable $exception) {
            throw new TransportException('Request to SendPulse API failed.', 0, $exception);
        }

        if (! $response->successful() || $response->json('result') !== true) {
            throw new TransportException($this->failureMessage($response), $response->status());
        }

        $messageId = $response->json('id');

        if (is_string($messageId) && $messageId !== '') {
            $message->setMessageId($messageId);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(SentMessage $message): array
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $html = (string) ($email->getHtmlBody() ?? '');
        $text = (string) ($email->getTextBody() ?? trim(strip_tags($html)));
        $from = $email->getFrom()[0] ?? $message->getEnvelope()->getSender();
        $replyTo = $email->getReplyTo()[0] ?? null;
        $cc = $this->addresses($email->getCc());
        $bcc = $this->addresses($email->getBcc());

        $payload = [
            'html' => base64_encode($html),
            'text' => $text,
            'subject' => (string) $email->getSubject(),
            'from' => $this->address($from),
            'to' => $this->addresses($this->directRecipients($email, $message)),
        ];

        if ($replyTo) {
            $payload['reply_to'] = $this->address($replyTo);
        }

        if ($cc !== []) {
            $payload['cc'] = $cc;
        }

        if ($bcc !== []) {
            $payload['bcc'] = $bcc;
        }

        $attachments = $this->attachments($email);

        if ($attachments !== []) {
            $payload['attachments_binary'] = $attachments;
        }

        return $payload;
    }

    /**
     * @return array<int, Address>
     */
    private function directRecipients(Email $email, SentMessage $message): array
    {
        $copyRecipients = array_map(
            fn (Address $address): string => Str::lower($address->getAddress()),
            [...$email->getCc(), ...$email->getBcc()],
        );

        return array_values(array_filter(
            $message->getEnvelope()->getRecipients(),
            fn (Address $address): bool => ! in_array(Str::lower($address->getAddress()), $copyRecipients, true),
        ));
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return array<int, array{name: string, email: string}>
     */
    private function addresses(array $addresses): array
    {
        return array_map($this->address(...), $addresses);
    }

    /**
     * @return array{name: string, email: string}
     */
    private function address(Address $address): array
    {
        return [
            'name' => $address->getName(),
            'email' => $address->getAddress(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $filename = $headers->getHeaderParameter('Content-Disposition', 'filename');

            if (! is_string($filename) || $filename === '') {
                continue;
            }

            $attachments[$filename] = str_replace(["\r", "\n"], '', $attachment->bodyToString());
        }

        return $attachments;
    }

    private function failureMessage(Response $response): string
    {
        $message = $response->json('message');
        $detail = is_string($message) && $message !== ''
            ? Str::limit(strip_tags($message), 500)
            : 'Unknown error';

        return sprintf('SendPulse API rejected the email (HTTP %d): %s', $response->status(), $detail);
    }

    public function __toString(): string
    {
        return 'sendpulse-api';
    }
}

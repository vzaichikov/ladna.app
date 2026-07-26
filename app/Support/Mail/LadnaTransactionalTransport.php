<?php

namespace App\Support\Mail;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

class LadnaTransactionalTransport implements TransportInterface
{
    public function __construct(
        private readonly MailDeliveryTransportResolver $resolver,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        return $this->resolver->resolve()->send($message, $envelope);
    }

    public function __toString(): string
    {
        return 'ladna-transactional';
    }
}

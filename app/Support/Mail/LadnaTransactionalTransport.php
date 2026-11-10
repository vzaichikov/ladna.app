<?php

namespace App\Support\Mail;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

class LadnaTransactionalTransport implements TransportInterface
{
    public const RequireDeliveryHeader = 'X-Ladna-Require-Delivery';

    public function __construct(
        private readonly MailDeliveryTransportResolver $resolver,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $requireDelivery = $message instanceof Email && $message->getHeaders()->has(self::RequireDeliveryHeader);
        if ($message instanceof Email) {
            $message->getHeaders()->remove(self::RequireDeliveryHeader);
        }

        return $this->resolver->resolve(requireDelivery: $requireDelivery)->send($message, $envelope);
    }

    public function __toString(): string
    {
        return 'ladna-transactional';
    }
}

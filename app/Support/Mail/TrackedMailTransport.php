<?php

namespace App\Support\Mail;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Throwable;

class TrackedMailTransport implements TransportInterface
{
    public const DeliveryIdHeader = 'X-Ladna-Email-Delivery-Id';

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly EmailDeliveryRecorder $recorder,
        private readonly string $engine,
        private readonly bool $fallback,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $deliveryId = $this->deliveryId($message);

        if ($message instanceof Email) {
            $message->getHeaders()->remove(self::DeliveryIdHeader);
        }

        try {
            $sentMessage = $this->transport->send($message, $envelope);
        } catch (Throwable $exception) {
            if ($deliveryId) {
                try {
                    $this->recorder->recordTransportFailure($deliveryId, $exception);
                } catch (Throwable $auditException) {
                    report($auditException);
                }
            }

            throw $exception;
        }

        if ($deliveryId) {
            try {
                $this->recorder->recordTransportSuccess(
                    $deliveryId,
                    $this->engine,
                    $this->fallback,
                    $sentMessage?->getMessageId(),
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $sentMessage;
    }

    public function __toString(): string
    {
        return $this->engine;
    }

    private function deliveryId(RawMessage $message): ?int
    {
        if (! $message instanceof Email) {
            return null;
        }

        $header = $message->getHeaders()->get(self::DeliveryIdHeader);
        $value = $header?->getBodyAsString();

        return is_numeric($value) ? (int) $value : null;
    }
}

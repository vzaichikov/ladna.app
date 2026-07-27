<?php

namespace App\Listeners;

use App\Support\Mail\EmailDeliveryRecorder;
use Illuminate\Mail\Events\MessageSent;
use Throwable;

class TrackTransactionalMailSent
{
    public function __construct(private readonly EmailDeliveryRecorder $recorder) {}

    public function handle(MessageSent $event): void
    {
        $deliveryId = $event->data['emailDeliveryId'] ?? null;

        if (! is_numeric($deliveryId)) {
            return;
        }

        try {
            $this->recorder->markSent(
                (int) $deliveryId,
                $event->sent->getSymfonySentMessage()->getMessageId(),
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

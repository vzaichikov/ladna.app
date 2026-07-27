<?php

namespace App\Listeners;

use App\Enums\EmailDeliveryStatus;
use App\Models\EmailDelivery;
use App\Support\Mail\EmailDeliveryRecorder;
use App\Support\Mail\EmailScenarioSettings;
use Illuminate\Mail\Events\MessageSending;

class TrackTransactionalMailSending
{
    public function __construct(
        private readonly EmailScenarioSettings $scenarioSettings,
        private readonly EmailDeliveryRecorder $recorder,
    ) {}

    public function handle(MessageSending $event): ?bool
    {
        $deliveryId = $this->deliveryId($event->data);

        if (! $deliveryId) {
            return null;
        }

        $delivery = EmailDelivery::query()->find($deliveryId);

        if (! $delivery) {
            return null;
        }

        if (in_array($delivery->status, [EmailDeliveryStatus::Sent, EmailDeliveryStatus::Skipped], true)) {
            return false;
        }

        if (! $this->scenarioSettings->isEnabled($delivery->scenario)) {
            $this->recorder->markSkipped($deliveryId, $event->message, 'scenario_disabled_before_send');

            return false;
        }

        $this->recorder->startProcessing($deliveryId, $event->message);

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function deliveryId(array $data): ?int
    {
        $deliveryId = $data['emailDeliveryId'] ?? null;

        return is_numeric($deliveryId) ? (int) $deliveryId : null;
    }
}

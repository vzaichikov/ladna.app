<?php

namespace App\Support\SaasBilling;

use App\Enums\EmailScenario;
use App\Models\AccountSubscription;
use App\Models\AccountSubscriptionNotification;
use App\Support\Mail\EmailScenarioSettings;
use App\Support\Mail\TransactionalMailDispatcher;
use Carbon\CarbonInterface;

class SendBillingLifecycleNotification
{
    public function __construct(
        private readonly TransactionalMailDispatcher $mailDispatcher,
        private readonly EmailScenarioSettings $scenarioSettings,
    ) {}

    /**
     * @param  array<string, scalar|null>  $parameters
     */
    public function execute(
        AccountSubscription $subscription,
        string $type,
        CarbonInterface $scheduledFor,
        array $parameters = [],
    ): AccountSubscriptionNotification {
        $notification = $subscription->billingNotifications()->firstOrCreate(
            [
                'notification_type' => $type,
                'scheduled_for' => $scheduledFor,
            ],
            ['context' => $parameters],
        );

        if ($notification->sent_at || $notification->suppressed_at) {
            return $notification;
        }

        $scenario = EmailScenario::fromLifecycleType($type);

        if (! $this->scenarioSettings->isEnabled($scenario)) {
            $notification->forceFill(['suppressed_at' => now()])->save();

            return $notification->refresh();
        }

        $this->mailDispatcher->saasLifecycleNotice($subscription, $type, $parameters);
        $notification->forceFill(['sent_at' => now()])->save();

        return $notification->refresh();
    }
}

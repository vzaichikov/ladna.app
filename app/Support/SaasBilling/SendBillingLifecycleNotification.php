<?php

namespace App\Support\SaasBilling;

use App\Models\AccountSubscription;
use App\Models\AccountSubscriptionNotification;
use App\Support\Mail\TransactionalMailDispatcher;
use Carbon\CarbonInterface;

class SendBillingLifecycleNotification
{
    public function __construct(private readonly TransactionalMailDispatcher $mailDispatcher) {}

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

        if ($notification->sent_at) {
            return $notification;
        }

        $this->mailDispatcher->saasLifecycleNotice($subscription, $type, $parameters);
        $notification->forceFill(['sent_at' => now()])->save();

        return $notification->refresh();
    }
}

<?php

namespace App\Enums;

enum EmailScenarioGroup: string
{
    case CustomerBookings = 'customer_bookings';
    case CustomerPasses = 'customer_passes';
    case SubscriptionPayments = 'subscription_payments';
    case SubscriptionLifecycle = 'subscription_lifecycle';

    public function labelKey(): string
    {
        return 'app.email_scenario_group_'.$this->value;
    }
}

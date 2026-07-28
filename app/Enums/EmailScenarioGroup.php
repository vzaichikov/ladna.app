<?php

namespace App\Enums;

enum EmailScenarioGroup: string
{
    case CustomerBookings = 'customer_bookings';
    case CustomerPasses = 'customer_passes';
    case SubscriptionPayments = 'subscription_payments';
    case SubscriptionLifecycle = 'subscription_lifecycle';
    case EventTickets = 'event_tickets';

    public function labelKey(): string
    {
        return 'app.email_scenario_group_'.$this->value;
    }
}

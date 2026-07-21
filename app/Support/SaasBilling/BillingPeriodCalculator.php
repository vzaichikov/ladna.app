<?php

namespace App\Support\SaasBilling;

use App\Enums\SubscriptionBillingInterval;
use Carbon\CarbonInterface;

class BillingPeriodCalculator
{
    public function periodEnd(CarbonInterface $startsAt, SubscriptionBillingInterval $interval): CarbonInterface
    {
        return match ($interval) {
            SubscriptionBillingInterval::Monthly => $startsAt->copy()->addMonthNoOverflow(),
            SubscriptionBillingInterval::Annual => $startsAt->copy()->addYearNoOverflow(),
        };
    }

    public function trialEnd(CarbonInterface $startsAt, int $trialDays): CarbonInterface
    {
        return $startsAt->copy()->addDays($trialDays);
    }
}

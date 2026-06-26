<?php

namespace App\Support\SaasBilling;

use App\Models\SubscriptionPlan;

class SaasBillingPlans
{
    public function demoPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::query()
            ->publicSignup()
            ->demo()
            ->where('slug', 'demo-month')
            ->first()
            ?? SubscriptionPlan::query()
                ->publicSignup()
                ->demo()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->firstOrFail();
    }

    public function standardPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::query()
            ->active()
            ->standard()
            ->where('slug', 'standard-monthly')
            ->first()
            ?? SubscriptionPlan::query()
                ->active()
                ->standard()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->firstOrFail();
    }
}

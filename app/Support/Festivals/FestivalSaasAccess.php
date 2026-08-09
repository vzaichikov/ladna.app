<?php

namespace App\Support\Festivals;

use App\Enums\FestivalEditionPurchaseStatus;
use App\Enums\SubscriptionPlanType;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Support\SaasBilling\AccountSubscriptionAccess;

class FestivalSaasAccess
{
    public function __construct(private readonly AccountSubscriptionAccess $subscriptions) {}

    public function canPurchase(Account $account): bool
    {
        $account->loadMissing('subscription.plan');
        $subscription = $account->subscription;

        return $account->enable_festivals
            && ! $account->isReadOnlyDemo()
            && $subscription !== null
            && $subscription->plan?->plan_type !== SubscriptionPlanType::Demo
            && $subscription->isCurrent()
            && $this->subscriptions->canEditStudio($account)
            && $subscription->plan?->festivalTariffPackages()->where('is_active', true)->exists();
    }

    public function purchaseFor(FestivalEdition $edition): ?FestivalEditionPurchase
    {
        return $edition->relationLoaded('purchase')
            ? $edition->purchase
            : $edition->purchase()->first();
    }

    public function editionIsReadOnly(FestivalEdition $edition): bool
    {
        return $this->purchaseFor($edition)?->status === FestivalEditionPurchaseStatus::PaymentReversed;
    }

    public function assertEditionWritable(FestivalEdition $edition): void
    {
        abort_if($this->editionIsReadOnly($edition), 423, __('app.festival_payment_reversed_readonly'));
    }
}

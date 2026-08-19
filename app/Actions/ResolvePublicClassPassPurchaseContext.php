<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Location;
use App\Support\ScheduleKindRegistry;

class ResolvePublicClassPassPurchaseContext
{
    /**
     * @return array{0: Account, 1: Location, 2: ClassPassPlan}
     */
    public function execute(string $accountSlug, string $locationSlug, string $classPassPlanSlug): array
    {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $location = $account->locations()
            ->where('slug', $locationSlug)
            ->where('is_active', true)
            ->firstOrFail();
        $classPassPlan = $account->classPassPlans()
            ->active()
            ->where('slug', $classPassPlanSlug)
            ->with(['classTypes', 'trainerTypes', 'rooms.location'])
            ->firstOrFail();

        abort_unless($account->hasScheduleKindEnabled($classPassPlan->schedule_kind), 404);
        abort_unless(ScheduleKindRegistry::hasCapability($classPassPlan->schedule_kind, 'class_pass_eligible'), 404);
        abort_unless($this->planIsVisibleForLocation($classPassPlan, $location), 404);

        return [$account, $location, $classPassPlan];
    }

    private function planIsVisibleForLocation(ClassPassPlan $classPassPlan, Location $location): bool
    {
        return $classPassPlan->rooms->isEmpty()
            || $classPassPlan->rooms->contains(fn ($room): bool => $room->location_id === $location->id);
    }
}

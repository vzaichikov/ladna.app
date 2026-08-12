<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalChargeDuePolicy;
use App\Enums\FestivalChargePricingMode;
use App\Models\FestivalChargeDefinition;
use App\Models\FestivalEntry;
use Carbon\CarbonInterface;

class FestivalChargeDefinitionResolver
{
    public function amount(FestivalChargeDefinition $definition, FestivalEntry $entry): int
    {
        if ($definition->pricing_mode !== FestivalChargePricingMode::Roster) {
            return $definition->amount_cents;
        }

        $memberCount = $entry->relationLoaded('participants')
            ? $entry->participants->count()
            : $entry->participants()->count();
        $additionalMembers = max(0, $memberCount - (int) $definition->included_members);

        return $definition->amount_cents + ($additionalMembers * (int) $definition->additional_member_amount_cents);
    }

    public function dueAt(FestivalChargeDefinition $definition, ?CarbonInterface $approvedAt = null): ?CarbonInterface
    {
        if ($definition->due_policy === FestivalChargeDuePolicy::Fixed) {
            return $definition->due_at;
        }

        if ($approvedAt === null) {
            return null;
        }

        $relativeDueAt = $approvedAt->copy()->addDays((int) $definition->due_days_after_approval);

        return $definition->due_hard_cap_at && $definition->due_hard_cap_at->lessThan($relativeDueAt)
            ? $definition->due_hard_cap_at
            : $relativeDueAt;
    }
}

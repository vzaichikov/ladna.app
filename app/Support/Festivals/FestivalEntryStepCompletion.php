<?php

namespace App\Support\Festivals;

use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalRequirementInputType;
use App\Enums\FestivalRequirementStatus;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalEntryStep;
use Illuminate\Validation\ValidationException;

class FestivalEntryStepCompletion
{
    public function requirementsComplete(FestivalEntryStep $step): bool
    {
        $step->loadMissing(['requirements.definition', 'requirements.submissions']);

        return $step->requirements->every(fn (FestivalEntryRequirement $requirement): bool => $this->requirementComplete($requirement));
    }

    public function requirementComplete(FestivalEntryRequirement $requirement): bool
    {
        $requirement->loadMissing(['definition', 'submissions']);
        $agreement = $requirement->definition->input_type === FestivalRequirementInputType::Agreement;
        $blocking = $requirement->definition->is_required || $agreement;

        if (! $blocking || (! $agreement && $requirement->status === FestivalRequirementStatus::Waived)) {
            return true;
        }

        return in_array($requirement->status, [FestivalRequirementStatus::Submitted, FestivalRequirementStatus::Accepted], true)
            && $requirement->hasSubmittedResponse();
    }

    public function chargesComplete(FestivalEntryStep $step): bool
    {
        $step->loadMissing('charges');

        return $step->charges->every(fn ($charge): bool => in_array($charge->status, [FestivalChargeStatus::Paid, FestivalChargeStatus::Cancelled], true));
    }

    public function assertRequirementsComplete(FestivalEntryStep $step, string $key = 'step'): void
    {
        if (! $this->requirementsComplete($step)) {
            throw ValidationException::withMessages([$key => __('app.festival_step_requirements_incomplete')]);
        }
    }

    public function assertChargesComplete(FestivalEntryStep $step, string $key = 'step'): void
    {
        if (! $this->chargesComplete($step)) {
            throw ValidationException::withMessages([$key => __('app.festival_step_payment_required')]);
        }
    }
}

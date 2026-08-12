<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalPaymentStatus;
use App\Enums\FestivalQualificationStatus;
use App\Models\FestivalCategory;
use App\Models\FestivalEntry;
use App\Models\User;
use App\Support\Festivals\FestivalRuleRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReassignFestivalEntryCategory
{
    public function __construct(
        private readonly FestivalRuleRegistry $rules,
        private readonly ActivateFestivalParticipationCharges $activateParticipationCharges,
        private readonly FestivalActivityRecorder $activity,
    ) {}

    public function execute(FestivalEntry $entry, FestivalCategory $targetCategory, User $actor, string $reason): FestivalEntry
    {
        return DB::transaction(function () use ($entry, $targetCategory, $actor, $reason): FestivalEntry {
            $entry = FestivalEntry::query()
                ->with(['edition', 'category', 'participants', 'steps.workflowStep', 'charges.paymentAttempts'])
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();
            $targetCategory = FestivalCategory::query()
                ->whereKey($targetCategory->id)
                ->where('account_id', $entry->account_id)
                ->where('festival_edition_id', $entry->festival_edition_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();

            if ($entry->qualification_status !== FestivalQualificationStatus::Passed) {
                throw ValidationException::withMessages(['festival_category_id' => __('app.festival_category_reassignment_requires_qualification')]);
            }
            if ($entry->category->festival_workflow_id !== $targetCategory->festival_workflow_id) {
                throw ValidationException::withMessages(['festival_category_id' => __('app.festival_category_reassignment_workflow_mismatch')]);
            }

            $qualificationStep = $entry->steps->first(fn ($step): bool => $step->workflowStep->review_effect?->value === 'qualification');
            $laterProgress = $entry->steps->contains(fn ($step): bool => $qualificationStep
                && $step->workflowStep->sort_order > $qualificationStep->workflowStep->sort_order
                && $step->status !== FestivalEntryStepStatus::Draft);
            if ($laterProgress) {
                throw ValidationException::withMessages(['festival_category_id' => __('app.festival_category_reassignment_progressed')]);
            }

            $participationCharges = $entry->charges->where('kind', 'participation');
            $protectedCharge = $participationCharges->contains(function ($charge): bool {
                $protectedStatus = in_array($charge->status, [
                    FestivalChargeStatus::PaymentPending,
                    FestivalChargeStatus::Paid,
                    FestivalChargeStatus::PaidRequiresRefund,
                    FestivalChargeStatus::Refunded,
                ], true);
                $liveAttempt = $charge->paymentAttempts->contains(fn ($attempt): bool => $attempt->status === FestivalPaymentStatus::Pending && (! $attempt->expires_at || $attempt->expires_at->isFuture()));

                return $protectedStatus || $liveAttempt;
            });
            if ($protectedCharge) {
                throw ValidationException::withMessages(['festival_category_id' => __('app.festival_category_reassignment_payment_started')]);
            }

            $this->rules->validateEntry($entry->edition, $targetCategory, $entry->participants, false, $entry->submitted_at ?? now());
            $previousCategoryId = $entry->festival_category_id;
            $entry->forceFill([
                'festival_category_id' => $targetCategory->id,
                'track_artist' => null,
                'track_title' => null,
                'normalized_track_key' => null,
                'track_reserved_at' => null,
            ])->save();
            $this->activateParticipationCharges->execute($entry, $qualificationStep?->reviewed_at ?? now());
            $this->activity->record($entry, 'entry.category_reassigned', $entry->edition, $actor, [
                'from_category_id' => $previousCategoryId,
                'to_category_id' => $targetCategory->id,
                'reason' => $reason,
            ]);

            return $entry->refresh()->load('category');
        }, 3);
    }
}

<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalPaymentStatus;
use App\Enums\FestivalQualificationStatus;
use App\Enums\IntegrationProvider;
use App\Models\FestivalBattleMatch;
use App\Models\FestivalCategory;
use App\Models\FestivalCharge;
use App\Models\FestivalEntry;
use App\Models\FestivalPortalUser;
use App\Models\User;
use App\Support\Festivals\FestivalRuleRegistry;
use Illuminate\Database\QueryException;
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
        return $this->reassign($entry, $targetCategory, $actor, $reason, false);
    }

    public function executeForApplicant(FestivalEntry $entry, FestivalCategory $targetCategory, FestivalPortalUser $actor): FestivalEntry
    {
        return $this->reassign($entry, $targetCategory, $actor, __('app.festival_category_changed_by_applicant'), true);
    }

    public function applicantMayChange(FestivalEntry $entry): bool
    {
        $entry->loadMissing('charges.paymentAllocations');

        return $entry->status === FestivalEntryStatus::Draft && ! $this->applicantPaymentStarted($entry);
    }

    public function requiresManualPaymentHandling(FestivalEntry $entry): bool
    {
        $entry->loadMissing('charges');

        return $entry->charges->contains(fn (FestivalCharge $charge): bool => $charge->kind === 'participation'
            && in_array($charge->status, [
                FestivalChargeStatus::Paid,
                FestivalChargeStatus::PaidRequiresRefund,
                FestivalChargeStatus::Refunded,
            ], true));
    }

    private function reassign(FestivalEntry $entry, FestivalCategory $targetCategory, User|FestivalPortalUser $actor, string $reason, bool $applicantInitiated): FestivalEntry
    {
        return DB::transaction(function () use ($entry, $targetCategory, $actor, $reason, $applicantInitiated): FestivalEntry {
            $entry = FestivalEntry::query()
                ->with(['edition', 'category', 'participants', 'steps.workflowStep'])
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();
            $charges = FestivalCharge::query()
                ->with('paymentAllocations.attempt')
                ->where('festival_entry_id', $entry->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $entry->setRelation('charges', $charges);

            if ($applicantInitiated) {
                if ($entry->festival_portal_user_id !== $actor->id || $entry->account_id !== $actor->account_id) {
                    throw ValidationException::withMessages(['festival_category_id' => __('app.festival_category_unavailable')]);
                }
                if ($entry->status !== FestivalEntryStatus::Draft) {
                    throw ValidationException::withMessages(['festival_category_id' => __('app.festival_category_change_draft_only')]);
                }
                if ($this->applicantPaymentStarted($entry)) {
                    throw ValidationException::withMessages(['festival_category_id' => __('app.festival_category_change_payment_started')]);
                }
            }
            $targetCategory = FestivalCategory::query()
                ->whereKey($targetCategory->id)
                ->where('account_id', $entry->account_id)
                ->where('festival_edition_id', $entry->festival_edition_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();

            if ($entry->festival_category_id !== $targetCategory->id && $targetCategory->applicationCapacityReached()) {
                throw ValidationException::withMessages(['festival_category_id' => __('app.festival_category_full')]);
            }

            if ($entry->category->festival_workflow_id !== $targetCategory->festival_workflow_id) {
                throw ValidationException::withMessages(['festival_category_id' => __('app.festival_category_reassignment_workflow_mismatch')]);
            }
            if (! $applicantInitiated && $this->hasCompetitionProgress($entry)) {
                throw ValidationException::withMessages(['festival_category_id' => __('app.festival_category_reassignment_judging_started')]);
            }

            $qualificationStep = $entry->steps->first(fn ($step): bool => $step->workflowStep->review_effect?->value === 'qualification');
            $participationCharges = $entry->charges->where('kind', 'participation');
            $liveCheckout = ! $applicantInitiated && $participationCharges->contains(fn (FestivalCharge $charge): bool => $charge->allocatedPaymentAttempts()
                ->contains(fn ($attempt): bool => $attempt->status === FestivalPaymentStatus::Pending
                    && ($attempt->provider === IntegrationProvider::Monopay->value || ! $attempt->expires_at || $attempt->expires_at->isFuture())));
            if ($liveCheckout) {
                throw ValidationException::withMessages(['festival_category_id' => __('app.festival_category_reassignment_payment_started')]);
            }
            $manualPaymentHandling = ! $applicantInitiated && $this->requiresManualPaymentHandling($entry);
            if (! $manualPaymentHandling) {
                foreach ($participationCharges->where('status', FestivalChargeStatus::PaymentPending) as $charge) {
                    $charge->forceFill(['status' => FestivalChargeStatus::Failed])->save();
                }
            }

            $this->rules->validateEntry(
                $entry->edition,
                $targetCategory,
                $entry->participants,
                $applicantInitiated,
                $entry->submitted_at ?? now(),
                (bool) $entry->submitted_at,
            );
            $previousCategoryId = $entry->festival_category_id;
            $preserveTrack = ! $applicantInitiated && ($entry->status === FestivalEntryStatus::Accepted
                || ($entry->status === FestivalEntryStatus::ChangesPending && $entry->registration_completed_at !== null));
            if ($preserveTrack && $entry->normalized_track_key !== null && FestivalEntry::query()
                ->where('festival_category_id', $targetCategory->id)
                ->where('normalized_track_key', $entry->normalized_track_key)
                ->whereKeyNot($entry->id)
                ->whereNotIn('status', [FestivalEntryStatus::Rejected->value, FestivalEntryStatus::Withdrawn->value])
                ->exists()) {
                throw ValidationException::withMessages(['festival_category_id' => __('app.festival_track_already_reserved')]);
            }
            $values = ['festival_category_id' => $targetCategory->id];
            if (! $preserveTrack) {
                $values += [
                    'track_artist' => null,
                    'track_title' => null,
                    'normalized_track_key' => null,
                    'track_reserved_at' => null,
                ];
            }
            try {
                $entry->forceFill($values)->save();
            } catch (QueryException $exception) {
                if ((string) $exception->getCode() !== '23000'
                    || ! str_contains($exception->getMessage(), 'festival_entries_category_track_unique')) {
                    throw $exception;
                }

                throw ValidationException::withMessages(['festival_category_id' => __('app.festival_track_already_reserved')]);
            }
            $qualificationApproved = ! $qualificationStep
                || ($qualificationStep->status === FestivalEntryStepStatus::Approved
                    && in_array($entry->qualification_status, [FestivalQualificationStatus::NotRequired, FestivalQualificationStatus::Passed], true));
            if ($qualificationApproved && ! $manualPaymentHandling) {
                $this->activateParticipationCharges->execute($entry, $qualificationStep?->reviewed_at ?? now());
            }
            $this->activity->record($entry, 'entry.category_reassigned', $entry->edition, $actor, [
                'from_category_id' => $previousCategoryId,
                'to_category_id' => $targetCategory->id,
                'reason' => $reason,
            ]);

            return $entry->refresh()->load('category');
        }, 3);
    }

    private function applicantPaymentStarted(FestivalEntry $entry): bool
    {
        return $entry->charges->contains(fn (FestivalCharge $charge): bool => $charge->hasPaymentHistory());
    }

    private function hasCompetitionProgress(FestivalEntry $entry): bool
    {
        return $entry->scoreSheets()->exists()
            || $entry->penalties()->exists()
            || $entry->result()->exists()
            || FestivalBattleMatch::query()
                ->where('account_id', $entry->account_id)
                ->where('festival_edition_id', $entry->festival_edition_id)
                ->where(function ($matches) use ($entry): void {
                    $matches->where('entry_a_id', $entry->id)
                        ->orWhere('entry_b_id', $entry->id)
                        ->orWhere('winner_entry_id', $entry->id);
                })
                ->exists();
    }
}

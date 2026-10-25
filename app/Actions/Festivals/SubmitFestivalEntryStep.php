<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEditionPurchaseStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalQualificationStatus;
use App\Enums\FestivalRequirementStatus;
use App\Enums\FestivalWorkflowReviewEffect;
use App\Enums\FestivalWorkflowReviewMode;
use App\Enums\FestivalWorkflowStepType;
use App\Models\FestivalCategory;
use App\Models\FestivalCharge;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryStep;
use App\Support\Festivals\FestivalEntryStepCompletion;
use App\Support\Festivals\FestivalEntryWorkflowState;
use App\Support\Festivals\FestivalParticipantLimits;
use App\Support\Festivals\FestivalRuleRegistry;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubmitFestivalEntryStep
{
    public function __construct(
        private readonly FestivalEntryWorkflowState $workflowState,
        private readonly FestivalEntryStepCompletion $completion,
        private readonly FestivalRuleRegistry $rules,
        private readonly FestivalParticipantLimits $participantLimits,
        private readonly FestivalActivityRecorder $activity,
        private readonly FestivalNotificationOutbox $notifications,
        private readonly QueueFestivalEntryStepCompletionNotification $completionNotifications,
        private readonly ReserveFestivalEntryTrack $reserveTrack,
    ) {}

    public function execute(FestivalEntry $entry, FestivalEntryStep $step, ?CarbonInterface $paymentReceivedAt = null): FestivalEntryStep
    {
        $completionNotificationToken = (string) Str::uuid();

        return DB::transaction(function () use ($entry, $step, $completionNotificationToken, $paymentReceivedAt): FestivalEntryStep {
            FestivalEdition::query()->whereKey($entry->festival_edition_id)->lockForUpdate()->firstOrFail();
            $purchase = FestivalEditionPurchase::query()->with('package')->where('festival_edition_id', $entry->festival_edition_id)->lockForUpdate()->first();
            abort_if($purchase?->status === FestivalEditionPurchaseStatus::PaymentReversed, 423, __('app.festival_payment_reversed_readonly'));

            $entry = FestivalEntry::query()->with(['edition', 'participants', 'portalUser', 'steps.workflowStep'])->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $category = FestivalCategory::query()
                ->whereKey($entry->festival_category_id)
                ->where('account_id', $entry->account_id)
                ->where('festival_edition_id', $entry->festival_edition_id)
                ->lockForUpdate()
                ->firstOrFail();
            $entry->setRelation('category', $category);
            $step = FestivalEntryStep::query()->with(['workflowStep', 'requirements.definition', 'requirements.selectedHelpers', 'requirements.submissions', 'charges'])->whereKey($step->id)->lockForUpdate()->firstOrFail();
            if ($step->workflowStep->type === FestivalWorkflowStepType::Summary) {
                throw ValidationException::withMessages(['step' => __('app.festival_summary_organizer_confirmation_required')]);
            }
            if ($paymentReceivedAt !== null) {
                $paymentReceivedAt = $step->charges
                    ->where('status', FestivalChargeStatus::Paid)
                    ->map(fn (FestivalCharge $charge): CarbonInterface => $charge->paid_at ?? now())
                    ->push($paymentReceivedAt)
                    ->max();
            }
            $this->workflowState->assertMutable($entry, $step, $paymentReceivedAt);
            $this->completion->assertRequirementsComplete($step);
            $this->completion->assertChargesComplete($step);

            $this->reserveTrack->execute($entry, $step);

            if ($step->workflowStep->type === FestivalWorkflowStepType::Application) {
                $this->submitApplication($entry, $purchase, $paymentReceivedAt);
            }

            $postConfirmationReview = $entry->status === FestivalEntryStatus::ChangesPending;
            $automatic = ! $postConfirmationReview && ! $step->isTechnicalCorrection() && $step->workflowStep->review_mode === FestivalWorkflowReviewMode::Automatic;
            $step->forceFill([
                'status' => $automatic ? FestivalEntryStepStatus::Approved : FestivalEntryStepStatus::Submitted,
                'submitted_at' => now(),
                'reviewed_at' => $automatic ? now() : null,
                'reviewed_by' => null,
                'review_notes' => null,
                'correction_due_at' => null,
            ])->save();

            if ($automatic) {
                $step->requirements()->where('status', FestivalRequirementStatus::Submitted->value)->update(['status' => FestivalRequirementStatus::Accepted->value, 'reviewed_at' => now()]);
            }

            if (! $postConfirmationReview && $entry->status !== FestivalEntryStatus::Submitted) {
                $entry->forceFill(['status' => FestivalEntryStatus::UnderReview])->save();
            }

            $this->activity->record($step, 'entry_step.submitted', $entry->edition, $entry->portalUser, ['step' => $step->workflowStep->code]);

            if ($automatic) {
                $this->completionNotifications->execute(
                    $step,
                    'step-auto:'.$step->id.':'.$completionNotificationToken,
                    queueOwnerTelegramAlert: false,
                );
            }

            return $step->refresh();
        }, 3);
    }

    private function submitApplication(FestivalEntry $entry, ?FestivalEditionPurchase $purchase, ?CarbonInterface $paymentReceivedAt = null): void
    {
        $firstSubmission = $entry->submitted_at === null;
        $this->rules->validateEntry($entry->edition, $entry->category, $entry->participants, $firstSubmission, $entry->submitted_at ?? now(), registrationAt: $paymentReceivedAt);

        if ($firstSubmission) {
            $this->participantLimits->assertAvailable($entry, $purchase);
        }

        $entry->forceFill([
            'status' => $entry->status === FestivalEntryStatus::ChangesPending ? FestivalEntryStatus::ChangesPending : FestivalEntryStatus::Submitted,
            'qualification_status' => $entry->steps->contains(fn (FestivalEntryStep $step): bool => $step->workflowStep->review_effect === FestivalWorkflowReviewEffect::Qualification) ? FestivalQualificationStatus::Pending : FestivalQualificationStatus::NotRequired,
            'submitted_at' => $entry->submitted_at ?? now(),
        ])->save();

        if ($firstSubmission) {
            $this->activity->record($entry, 'entry.submitted', $entry->edition, $entry->portalUser);
            $this->notifications->queueForEntry($entry, 'entry_submitted', ['entry_code' => $entry->code]);
        }
    }
}

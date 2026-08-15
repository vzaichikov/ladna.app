<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalEditionPurchaseStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalQualificationStatus;
use App\Enums\FestivalRequirementStatus;
use App\Enums\FestivalWorkflowReviewEffect;
use App\Enums\FestivalWorkflowReviewMode;
use App\Enums\FestivalWorkflowStepType;
use App\Models\FestivalCategory;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryStep;
use App\Support\Festivals\FestivalEntryStepCompletion;
use App\Support\Festivals\FestivalEntryWorkflowState;
use App\Support\Festivals\FestivalRuleRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitFestivalEntryStep
{
    public function __construct(
        private readonly FestivalEntryWorkflowState $workflowState,
        private readonly FestivalEntryStepCompletion $completion,
        private readonly FestivalRuleRegistry $rules,
        private readonly FestivalActivityRecorder $activity,
        private readonly FestivalNotificationOutbox $notifications,
        private readonly ReserveFestivalEntryTrack $reserveTrack,
    ) {}

    public function execute(FestivalEntry $entry, FestivalEntryStep $step): FestivalEntryStep
    {
        return DB::transaction(function () use ($entry, $step): FestivalEntryStep {
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
            $step = FestivalEntryStep::query()->with(['workflowStep', 'requirements.definition', 'requirements.submissions', 'charges'])->whereKey($step->id)->lockForUpdate()->firstOrFail();
            if ($step->workflowStep->type === FestivalWorkflowStepType::Summary) {
                throw ValidationException::withMessages(['step' => __('app.festival_summary_organizer_confirmation_required')]);
            }
            $this->workflowState->assertMutable($entry, $step);
            $this->completion->assertRequirementsComplete($step);
            $this->completion->assertChargesComplete($step);

            $this->reserveTrack->execute($entry, $step);

            if ($step->workflowStep->type === FestivalWorkflowStepType::Application) {
                $this->submitApplication($entry, $purchase);
            }

            $postConfirmationReview = $entry->status === FestivalEntryStatus::ChangesPending;
            $automatic = ! $postConfirmationReview && $step->workflowStep->review_mode === FestivalWorkflowReviewMode::Automatic;
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

            return $step->refresh();
        }, 3);
    }

    private function submitApplication(FestivalEntry $entry, ?FestivalEditionPurchase $purchase): void
    {
        $firstSubmission = $entry->submitted_at === null;
        $this->rules->validateEntry($entry->edition, $entry->category, $entry->participants, $firstSubmission, $entry->submitted_at ?? now());

        if ($firstSubmission) {
            $this->assertParticipantLimits($entry, $purchase);
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

    private function assertParticipantLimits(FestivalEntry $entry, ?FestivalEditionPurchase $purchase): void
    {
        $participantIds = $entry->participants->modelKeys();
        $activeStatuses = [FestivalEntryStatus::Submitted->value, FestivalEntryStatus::UnderReview->value, FestivalEntryStatus::ChangesPending->value, FestivalEntryStatus::Accepted->value];

        if ($entry->edition->max_entries_per_participant !== null) {
            $counts = DB::table('festival_entry_participant')
                ->join('festival_entries', 'festival_entries.id', '=', 'festival_entry_participant.festival_entry_id')
                ->where('festival_entries.festival_edition_id', $entry->festival_edition_id)
                ->whereIn('festival_entries.status', $activeStatuses)
                ->whereIn('festival_entry_participant.festival_participant_id', $participantIds)
                ->where('festival_entries.id', '!=', $entry->id)
                ->selectRaw('festival_entry_participant.festival_participant_id, count(*) as aggregate')
                ->groupBy('festival_entry_participant.festival_participant_id')
                ->pluck('aggregate', 'festival_entry_participant.festival_participant_id');

            if (collect($participantIds)->contains(fn (int $id): bool => (int) ($counts[$id] ?? 0) >= $entry->edition->max_entries_per_participant)) {
                throw ValidationException::withMessages(['participants' => __('app.festival_entry_limit_exceeded', ['limit' => $entry->edition->max_entries_per_participant])]);
            }
        }

        if (! $purchase) {
            return;
        }

        $distinctIds = DB::table('festival_entry_participant')
            ->join('festival_entries', 'festival_entries.id', '=', 'festival_entry_participant.festival_entry_id')
            ->where('festival_entries.festival_edition_id', $entry->festival_edition_id)
            ->whereIn('festival_entries.status', $activeStatuses)
            ->where('festival_entries.id', '!=', $entry->id)
            ->distinct()
            ->pluck('festival_entry_participant.festival_participant_id')
            ->merge($participantIds)
            ->unique();

        if ($distinctIds->count() > $purchase->package->max_participants) {
            throw ValidationException::withMessages(['participants' => __('app.festival_participant_limit_exceeded', ['limit' => $purchase->package->max_participants])]);
        }
    }
}

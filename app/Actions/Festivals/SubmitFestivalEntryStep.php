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
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryStep;
use App\Support\Festivals\FestivalEntryWorkflowState;
use App\Support\Festivals\FestivalRuleRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitFestivalEntryStep
{
    public function __construct(
        private readonly FestivalEntryWorkflowState $workflowState,
        private readonly FestivalRuleRegistry $rules,
        private readonly FestivalActivityRecorder $activity,
        private readonly FestivalNotificationOutbox $notifications,
    ) {}

    public function execute(FestivalEntry $entry, FestivalEntryStep $step): FestivalEntryStep
    {
        return DB::transaction(function () use ($entry, $step): FestivalEntryStep {
            $purchase = FestivalEditionPurchase::query()->where('festival_edition_id', $entry->festival_edition_id)->lockForUpdate()->first();
            abort_if($purchase?->status === FestivalEditionPurchaseStatus::PaymentReversed, 423, __('app.festival_payment_reversed_readonly'));

            $entry = FestivalEntry::query()->with(['edition', 'category.options.axis', 'participants', 'portalUser', 'steps'])->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $step = FestivalEntryStep::query()->with(['requirements.submissions', 'charges'])->whereKey($step->id)->lockForUpdate()->firstOrFail();
            $this->workflowState->assertMutable($entry, $step);
            $this->assertRequirementsComplete($step);
            $this->assertChargesPaid($step);

            if ($step->type === FestivalWorkflowStepType::Application) {
                $this->submitApplication($entry, $purchase);
            }

            $automatic = $step->review_mode === FestivalWorkflowReviewMode::Automatic;
            $step->forceFill([
                'status' => $automatic ? FestivalEntryStepStatus::Approved : FestivalEntryStepStatus::Submitted,
                'submitted_at' => now(),
                'reviewed_at' => $automatic ? now() : null,
                'reviewed_by' => null,
                'review_notes' => null,
                'revision_due_at' => null,
            ])->save();

            if ($automatic) {
                $step->requirements()->where('status', FestivalRequirementStatus::Submitted->value)->update(['status' => FestivalRequirementStatus::Accepted->value, 'reviewed_at' => now()]);
            }

            if ($step->type === FestivalWorkflowStepType::Summary && $automatic) {
                $entry->forceFill([
                    'status' => FestivalEntryStatus::Accepted,
                    'accepted_at' => now(),
                    'registration_completed_at' => now(),
                ])->save();
                $this->notifications->queueForEntry($entry, 'entry_reviewed', ['entry_code' => $entry->code, 'status' => FestivalEntryStatus::Accepted->value]);
            } elseif ($entry->status !== FestivalEntryStatus::Submitted) {
                $entry->forceFill(['status' => FestivalEntryStatus::UnderReview])->save();
            }

            $this->activity->record($step, 'entry_step.submitted', $entry->edition, $entry->portalUser, ['step' => $step->code]);

            return $step->refresh();
        }, 3);
    }

    private function assertRequirementsComplete(FestivalEntryStep $step): void
    {
        $missing = $step->requirements
            ->where('is_required', true)
            ->contains(fn ($requirement): bool => ! in_array($requirement->status, [FestivalRequirementStatus::Submitted, FestivalRequirementStatus::Accepted, FestivalRequirementStatus::Waived], true));

        if ($missing) {
            throw ValidationException::withMessages(['step' => __('app.festival_step_requirements_incomplete')]);
        }
    }

    private function assertChargesPaid(FestivalEntryStep $step): void
    {
        $blocking = $step->charges->contains(fn ($charge): bool => ! in_array($charge->status, [FestivalChargeStatus::Paid, FestivalChargeStatus::Cancelled], true));
        if ($blocking) {
            throw ValidationException::withMessages(['step' => __('app.festival_step_payment_required')]);
        }
    }

    private function submitApplication(FestivalEntry $entry, ?FestivalEditionPurchase $purchase): void
    {
        $firstSubmission = $entry->submitted_at === null;
        $this->rules->validateEntrySnapshot($entry->edition, $entry, $firstSubmission);

        if ($firstSubmission) {
            $this->assertParticipantLimits($entry, $purchase);
        }

        $entry->forceFill([
            'status' => FestivalEntryStatus::Submitted,
            'qualification_status' => $entry->steps->firstWhere('review_effect', FestivalWorkflowReviewEffect::Qualification) ? FestivalQualificationStatus::Pending : FestivalQualificationStatus::NotRequired,
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
        $activeStatuses = [FestivalEntryStatus::Submitted->value, FestivalEntryStatus::UnderReview->value, FestivalEntryStatus::Accepted->value];

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

        if ($distinctIds->count() > $purchase->max_participants) {
            throw ValidationException::withMessages(['participants' => __('app.festival_participant_limit_exceeded', ['limit' => $purchase->max_participants])]);
        }
    }
}

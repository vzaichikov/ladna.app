<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalNotificationType;
use App\Enums\FestivalQualificationStatus;
use App\Enums\FestivalRequirementStatus;
use App\Enums\FestivalWorkflowStepType;
use App\Models\FestivalCategory;
use App\Models\FestivalCharge;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalEntryStep;
use App\Models\User;
use App\Support\Festivals\FestivalEntryStepCompletion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FullyConfirmFestivalEntry
{
    public function __construct(
        private readonly FestivalEntryStepCompletion $completion,
        private readonly FestivalActivityRecorder $activity,
        private readonly FestivalNotificationOutbox $notifications,
        private readonly ReserveFestivalEntryTrack $reserveTrack,
    ) {}

    public function execute(FestivalEntry $entry, User $reviewer): FestivalEntry
    {
        $notificationToken = (string) Str::uuid();

        return DB::transaction(function () use ($entry, $reviewer, $notificationToken): FestivalEntry {
            $entry = FestivalEntry::query()
                ->with(['account', 'edition', 'portalUser'])
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(in_array($entry->status, [FestivalEntryStatus::Submitted, FestivalEntryStatus::UnderReview, FestivalEntryStatus::ChangesPending], true), 409);

            $category = FestivalCategory::query()
                ->whereKey($entry->festival_category_id)
                ->where('account_id', $entry->account_id)
                ->where('festival_edition_id', $entry->festival_edition_id)
                ->lockForUpdate()
                ->firstOrFail();
            $steps = FestivalEntryStep::query()
                ->with('workflowStep')
                ->where('festival_entry_id', $entry->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $requirements = FestivalEntryRequirement::query()
                ->with(['definition', 'selectedHelpers', 'submissions'])
                ->where('festival_entry_id', $entry->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $charges = FestivalCharge::query()
                ->where('festival_entry_id', $entry->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($steps as $step) {
                $step->setRelation('requirements', $requirements->where('festival_entry_step_id', $step->id)->values());
                $step->setRelation('charges', $charges->where('festival_entry_step_id', $step->id)->values());
            }
            $entry->setRelation('steps', $steps);
            $entry->setRelation('charges', $charges);

            $summarySteps = $steps->filter(fn (FestivalEntryStep $step): bool => $step->workflowStep->type === FestivalWorkflowStepType::Summary);
            if ($summarySteps->count() !== 1) {
                throw ValidationException::withMessages(['festival_application' => __('app.festival_full_confirm_summary_invalid')]);
            }

            $summary = $summarySteps->first();
            $registrationSteps = $steps->reject(fn (FestivalEntryStep $step): bool => $step->is($summary));
            $changedSteps = collect();

            if ($entry->status === FestivalEntryStatus::ChangesPending) {
                $invalidStep = $registrationSteps->first(fn (FestivalEntryStep $step): bool => ! in_array($step->status, [FestivalEntryStepStatus::Approved, FestivalEntryStepStatus::Submitted], true));
                if ($invalidStep) {
                    throw ValidationException::withMessages(['festival_application' => __('app.festival_full_confirm_steps_incomplete')]);
                }

                $changedSteps = $registrationSteps->where('status', FestivalEntryStepStatus::Submitted);
                foreach ($changedSteps as $step) {
                    $this->completion->assertRequirementsComplete($step, 'festival_application');
                    $this->completion->assertChargesComplete($step, 'festival_application');
                }
            } elseif ($registrationSteps->contains(fn (FestivalEntryStep $step): bool => $step->status !== FestivalEntryStepStatus::Approved)) {
                throw ValidationException::withMessages(['festival_application' => __('app.festival_full_confirm_steps_incomplete')]);
            }

            if (! in_array($entry->qualification_status, [FestivalQualificationStatus::Passed, FestivalQualificationStatus::NotRequired], true)) {
                throw ValidationException::withMessages(['festival_application' => __('app.festival_full_confirm_qualification_incomplete')]);
            }

            if ($charges->contains(fn (FestivalCharge $charge): bool => ! in_array($charge->status, [FestivalChargeStatus::Paid, FestivalChargeStatus::Cancelled], true))) {
                throw ValidationException::withMessages(['festival_application' => __('app.festival_full_confirm_payments_incomplete')]);
            }

            if ($category->applicationCapacityReached(excludingEntry: $entry)) {
                throw ValidationException::withMessages(['festival_category_id' => __('app.festival_category_full')]);
            }

            $reviewedAt = now();
            foreach ($changedSteps as $step) {
                $this->reserveTrack->execute($entry, $step);
                foreach ($step->requirements as $requirement) {
                    if ($requirement->status === FestivalRequirementStatus::Submitted && $requirement->hasSubmittedResponse()) {
                        $requirement->forceFill([
                            'status' => FestivalRequirementStatus::Accepted,
                            'reviewed_by' => $reviewer->id,
                            'reviewed_at' => $reviewedAt,
                            'review_notes' => null,
                        ])->save();
                    }
                }
                $step->forceFill([
                    'status' => FestivalEntryStepStatus::Approved,
                    'reviewed_by' => $reviewer->id,
                    'reviewed_at' => $reviewedAt,
                    'review_notes' => null,
                    'correction_due_at' => null,
                ])->save();
            }

            $summary->forceFill([
                'status' => FestivalEntryStepStatus::Approved,
                'submitted_at' => $summary->submitted_at ?? $reviewedAt,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => $reviewedAt,
                'review_notes' => null,
                'correction_due_at' => null,
            ])->save();
            $entry->forceFill([
                'status' => FestivalEntryStatus::Accepted,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => $reviewedAt,
                'review_notes' => null,
                'accepted_at' => $entry->accepted_at ?? $reviewedAt,
                'registration_completed_at' => $entry->registration_completed_at ?? $reviewedAt,
                'rejected_at' => null,
            ])->save();

            $this->activity->record($entry, 'entry.reviewed', $entry->edition, $reviewer, [
                'status' => FestivalEntryStatus::Accepted->value,
                'decision' => 'fully_confirmed',
            ]);
            $this->notifications->queueForEntry($entry, FestivalNotificationType::EntryReviewed, [
                'status' => FestivalEntryStatus::Accepted->value,
                'comment' => null,
            ], 'full-confirm:'.$notificationToken);

            return $entry->refresh();
        }, 3);
    }
}

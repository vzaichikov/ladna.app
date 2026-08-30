<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalNotificationType;
use App\Enums\FestivalRequirementStatus;
use App\Enums\FestivalWorkflowStepType;
use App\Models\FestivalCategory;
use App\Models\FestivalCharge;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalEntryStep;
use App\Models\User;
use App\Support\Festivals\FestivalEntryFinalConfirmation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FullyConfirmFestivalEntry
{
    public function __construct(
        private readonly FestivalEntryFinalConfirmation $finalConfirmation,
        private readonly FestivalActivityRecorder $activity,
        private readonly FestivalNotificationOutbox $notifications,
        private readonly QueueFestivalEntryStepCompletionNotification $completionNotifications,
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

            $changedSteps = $this->finalConfirmation->assertReady($entry, $category);
            $summary = $steps->first(
                fn (FestivalEntryStep $step): bool => $step->workflowStep->type === FestivalWorkflowStepType::Summary,
            );
            $completionSteps = $changedSteps
                ->filter(fn (FestivalEntryStep $step): bool => $step->status !== FestivalEntryStepStatus::Approved)
                ->values();

            if ($summary->status !== FestivalEntryStepStatus::Approved) {
                $completionSteps->push($summary);
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
            foreach ($completionSteps as $completionStep) {
                $this->completionNotifications->execute(
                    $completionStep,
                    'full-confirm-step:'.$completionStep->id.':'.$notificationToken,
                    queueOwnerTelegramAlert: false,
                );
            }
            $this->notifications->queueForEntry($entry, FestivalNotificationType::EntryReviewed, [
                'status' => FestivalEntryStatus::Accepted->value,
                'comment' => null,
            ], 'full-confirm:'.$notificationToken);

            return $entry->refresh();
        }, 3);
    }
}

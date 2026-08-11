<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalQualificationStatus;
use App\Enums\FestivalRequirementStatus;
use App\Enums\FestivalWorkflowReviewEffect;
use App\Models\FestivalEntryStep;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewFestivalEntryStep
{
    public function __construct(
        private readonly FestivalActivityRecorder $activity,
        private readonly FestivalNotificationOutbox $notifications,
    ) {}

    /** @param array<string, string> $requirementNotes */
    public function execute(FestivalEntryStep $step, User $reviewer, string $decision, ?string $comment = null, ?string $correctionDueAt = null, array $requirementNotes = []): FestivalEntryStep
    {
        if (! in_array($decision, ['approve', 'request_changes', 'reject_entry'], true)) {
            throw ValidationException::withMessages(['decision' => __('validation.in')]);
        }
        if (in_array($decision, ['request_changes', 'reject_entry'], true) && blank($comment)) {
            throw ValidationException::withMessages(['comment' => __('validation.required')]);
        }
        if ($decision === 'request_changes' && (blank($correctionDueAt) || now()->greaterThanOrEqualTo($correctionDueAt))) {
            throw ValidationException::withMessages(['correction_due_at' => __('validation.after', ['date' => 'now'])]);
        }

        return DB::transaction(function () use ($step, $reviewer, $decision, $comment, $correctionDueAt, $requirementNotes): FestivalEntryStep {
            $step = FestivalEntryStep::query()->with(['workflowStep', 'entry.edition', 'entry.portalUser', 'entry.steps.workflowStep', 'requirements'])->whereKey($step->id)->lockForUpdate()->firstOrFail();
            abort_unless($step->status === FestivalEntryStepStatus::Submitted, 409);

            foreach ($step->requirements as $requirement) {
                $note = $requirementNotes[(string) $requirement->id] ?? null;
                $requirement->forceFill([
                    'status' => $decision === 'approve' ? FestivalRequirementStatus::Accepted : $requirement->status,
                    'reviewed_by' => $reviewer->id,
                    'reviewed_at' => now(),
                    'review_notes' => filled($note) ? $note : $requirement->review_notes,
                ])->save();
            }

            if ($decision === 'approve') {
                $step->forceFill(['status' => FestivalEntryStepStatus::Approved, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_notes' => $comment, 'correction_due_at' => null])->save();
                if ($step->workflowStep->review_effect === FestivalWorkflowReviewEffect::Qualification) {
                    $step->entry->forceFill(['qualification_status' => FestivalQualificationStatus::Passed, 'status' => FestivalEntryStatus::UnderReview])->save();
                }
            } elseif ($decision === 'request_changes') {
                $step->forceFill(['status' => FestivalEntryStepStatus::ChangesRequested, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_notes' => $comment, 'correction_due_at' => $correctionDueAt])->save();
                $step->entry->steps()
                    ->whereHas('workflowStep', fn ($query) => $query->where('sort_order', '>', $step->workflowStep->sort_order))
                    ->where('status', '!=', FestivalEntryStepStatus::Draft->value)
                    ->update([
                        'status' => FestivalEntryStepStatus::Draft->value,
                        'submitted_at' => null,
                        'reviewed_at' => null,
                        'reviewed_by' => null,
                        'review_notes' => null,
                    ]);
                if ($step->workflowStep->review_effect === FestivalWorkflowReviewEffect::Qualification) {
                    $step->entry->forceFill(['qualification_status' => FestivalQualificationStatus::Pending, 'status' => FestivalEntryStatus::Submitted])->save();
                }
            } else {
                $step->forceFill(['status' => FestivalEntryStepStatus::Rejected, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_notes' => $comment])->save();
                $step->entry->forceFill([
                    'status' => FestivalEntryStatus::Rejected,
                    'qualification_status' => $step->workflowStep->review_effect === FestivalWorkflowReviewEffect::Qualification ? FestivalQualificationStatus::Failed : $step->entry->qualification_status,
                    'rejected_at' => now(),
                    'reviewed_at' => now(),
                    'reviewed_by' => $reviewer->id,
                    'review_notes' => $comment,
                ])->save();
            }

            $this->activity->record($step, 'entry_step.'.$decision, $step->entry->edition, $reviewer, ['step' => $step->workflowStep->code, 'comment' => $comment]);
            $this->notifications->queueForEntry($step->entry, 'entry_reviewed', ['entry_code' => $step->entry->code, 'step' => $step->workflowStep->title, 'decision' => $decision, 'comment' => $comment, 'correction_due_at' => $correctionDueAt]);

            return $step->refresh();
        }, 3);
    }
}

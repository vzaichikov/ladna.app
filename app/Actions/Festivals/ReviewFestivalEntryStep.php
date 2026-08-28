<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalNotificationType;
use App\Enums\FestivalQualificationStatus;
use App\Enums\FestivalRequirementStatus;
use App\Enums\FestivalWorkflowReviewEffect;
use App\Models\FestivalEntryStep;
use App\Models\User;
use App\Support\Festivals\FestivalEntryStepCompletion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReviewFestivalEntryStep
{
    public function __construct(
        private readonly FestivalActivityRecorder $activity,
        private readonly FestivalNotificationOutbox $notifications,
        private readonly ActivateFestivalParticipationCharges $activateParticipationCharges,
        private readonly FestivalEntryStepCompletion $completion,
        private readonly ReserveFestivalEntryTrack $reserveTrack,
        private readonly FullyDeclineFestivalEntry $fullyDecline,
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
        if ($decision === 'reject_entry') {
            $this->fullyDecline->execute($step->entry()->firstOrFail(), $reviewer, (string) $comment);

            return $step->refresh();
        }

        $reviewDedupeToken = (string) Str::uuid();

        return DB::transaction(function () use ($step, $reviewer, $decision, $comment, $correctionDueAt, $requirementNotes, $reviewDedupeToken): FestivalEntryStep {
            $step = FestivalEntryStep::query()->with(['workflowStep', 'entry.account', 'entry.edition', 'entry.portalUser', 'entry.steps.workflowStep', 'requirements.definition', 'requirements.selectedHelpers', 'requirements.submissions'])->whereKey($step->id)->lockForUpdate()->firstOrFail();
            abort_unless($step->status === FestivalEntryStepStatus::Submitted, 409);
            $postConfirmationReview = $step->entry->status === FestivalEntryStatus::ChangesPending;

            if ($decision === 'approve' && ! $this->completion->requirementsComplete($step)) {
                throw ValidationException::withMessages(['decision' => __('app.festival_step_requirements_incomplete')]);
            }

            if ($decision === 'approve' && $postConfirmationReview) {
                $this->reserveTrack->execute($step->entry, $step);
            }

            foreach ($step->requirements as $requirement) {
                $note = $requirementNotes[(string) $requirement->id] ?? null;
                $requirement->forceFill([
                    'status' => $decision === 'approve'
                        && $requirement->status !== FestivalRequirementStatus::Waived
                        && $requirement->hasSubmittedResponse()
                        ? FestivalRequirementStatus::Accepted
                        : $requirement->status,
                    'reviewed_by' => $reviewer->id,
                    'reviewed_at' => now(),
                    'review_notes' => filled($note) ? $note : $requirement->review_notes,
                ])->save();
            }

            if ($decision === 'approve') {
                $step->forceFill(['status' => FestivalEntryStepStatus::Approved, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_notes' => $comment, 'correction_due_at' => null])->save();
                if (! $postConfirmationReview && $step->workflowStep->review_effect === FestivalWorkflowReviewEffect::Qualification) {
                    $step->entry->forceFill(['qualification_status' => FestivalQualificationStatus::Passed, 'status' => FestivalEntryStatus::UnderReview])->save();
                    $this->activateParticipationCharges->execute($step->entry, $step->reviewed_at ?? now());
                }
            } elseif ($decision === 'request_changes') {
                $step->forceFill(['status' => FestivalEntryStepStatus::ChangesRequested, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_notes' => $comment, 'correction_due_at' => $correctionDueAt])->save();
                if (! $postConfirmationReview) {
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
                }
                if (! $postConfirmationReview && $step->workflowStep->review_effect === FestivalWorkflowReviewEffect::Qualification) {
                    $step->entry->forceFill(['qualification_status' => FestivalQualificationStatus::Pending, 'status' => FestivalEntryStatus::Submitted])->save();
                }
            }

            $this->activity->record($step, 'entry_step.'.$decision, $step->entry->edition, $reviewer, [
                'step' => $step->workflowStep->code,
                'comment' => $comment,
                'correction_due_at' => $decision === 'request_changes' ? $correctionDueAt : null,
            ]);
            $currentStepIndex = $step->entry->steps->search(fn (FestivalEntryStep $entryStep): bool => $entryStep->is($step));
            $nextStep = $decision === 'approve' && $currentStepIndex !== false
                ? $step->entry->steps->slice($currentStepIndex + 1)->first(fn (FestivalEntryStep $entryStep): bool => $entryStep->status !== FestivalEntryStepStatus::Approved)
                : null;
            $this->notifications->queueForEntry($step->entry, FestivalNotificationType::EntryStepReviewed, [
                'step' => $step->workflowStep->title,
                'decision' => $decision,
                'comment' => $comment,
                'correction_due_at' => $correctionDueAt,
                'entry_status' => $step->entry->status->value,
                'next_step' => $nextStep?->workflowStep->title,
                'next_step_type' => $nextStep?->workflowStep->type->value,
                'action_url' => route('festival.portal.entry-steps.show', [$step->entry->account->slug, $step->entry, $step]),
            ], 'step-review:'.$step->id.':'.$reviewDedupeToken);

            return $step->refresh();
        }, 3);
    }
}

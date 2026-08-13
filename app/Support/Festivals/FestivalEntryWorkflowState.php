<?php

namespace App\Support\Festivals;

use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalEntryStep;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class FestivalEntryWorkflowState
{
    public function __construct(
        private readonly FestivalRequirementDeadlineResolver $deadlineResolver,
        private readonly FestivalEntryStepCompletion $completion,
    ) {}

    /**
     * @return Collection<int, array{step: FestivalEntryStep, available: bool, mutable: bool, locked_reason: string|null, requirement_mutability: array<int, bool>, requirement_completeness: array<int, bool>, due_at: array<int, CarbonInterface|null>, editable_until: array<int, CarbonInterface|null>, requirements_complete: bool, has_blocking_charges: bool}>
     */
    public function forEntry(FestivalEntry $entry): Collection
    {
        $entry->loadMissing(['edition', 'steps.workflowStep', 'steps.requirements.definition.edition', 'steps.requirements.submissions', 'steps.charges']);
        $priorApproved = true;
        $states = collect();

        foreach ($entry->steps as $step) {
            $lockedReason = null;

            if (! $priorApproved) {
                $lockedReason = __('app.festival_step_locked_previous');
            } elseif ($step->workflowStep->opens_at?->isFuture()) {
                $lockedReason = __('app.festival_step_locked_until', ['date' => $step->workflowStep->opens_at->timezone($entry->edition->timezone)->format('d.m.Y H:i')]);
            } elseif ($step->status === FestivalEntryStepStatus::ChangesRequested && $step->correction_due_at?->isPast()) {
                $lockedReason = __('app.festival_step_correction_expired');
            } elseif ($step->workflowStep->due_at?->isPast() && $step->status === FestivalEntryStepStatus::Draft) {
                $lockedReason = __('app.festival_step_deadline_expired');
            } elseif ($step->status === FestivalEntryStepStatus::Rejected) {
                $lockedReason = __('app.festival_step_rejected');
            }

            $available = $lockedReason === null;
            $mutable = $available && in_array($step->status, [FestivalEntryStepStatus::Draft, FestivalEntryStepStatus::ChangesRequested], true);
            $requirementMutability = $step->requirements
                ->mapWithKeys(fn (FestivalEntryRequirement $requirement): array => [
                    $requirement->id => $this->requirementIsMutableForState($entry, $requirement, $mutable),
                ])
                ->all();
            $editableUntil = $step->requirements
                ->mapWithKeys(fn (FestivalEntryRequirement $requirement): array => [
                    $requirement->id => $this->deadlineResolver->allowsPostConfirmationEdits($requirement->definition)
                        ? $this->deadlineResolver->editableUntil($requirement->definition)
                        : null,
                ])
                ->all();
            $dueAt = $step->requirements
                ->mapWithKeys(fn (FestivalEntryRequirement $requirement): array => [
                    $requirement->id => $this->deadlineResolver->dueAt($requirement->definition),
                ])
                ->all();
            $requirementCompleteness = $step->requirements
                ->mapWithKeys(fn (FestivalEntryRequirement $requirement): array => [
                    $requirement->id => $this->completion->requirementComplete($requirement),
                ])
                ->all();
            $states->push(compact('step', 'available', 'mutable', 'lockedReason') + [
                'locked_reason' => $lockedReason,
                'requirement_mutability' => $requirementMutability,
                'editable_until' => $editableUntil,
                'due_at' => $dueAt,
                'requirement_completeness' => $requirementCompleteness,
                'requirements_complete' => $this->completion->requirementsComplete($step),
                'has_blocking_charges' => ! $this->completion->chargesComplete($step),
            ]);
            $priorApproved = $priorApproved && $step->status === FestivalEntryStepStatus::Approved;
        }

        return $states;
    }

    public function assertMutable(FestivalEntry $entry, FestivalEntryStep $step): void
    {
        abort_unless($step->festival_entry_id === $entry->id, 404);
        $state = $this->forEntry($entry)->first(fn (array $state): bool => $state['step']->is($step));
        abort_unless($state && $state['mutable'], 409, $state['locked_reason'] ?? __('app.festival_step_locked_previous'));
    }

    public function assertPaymentAvailable(FestivalEntry $entry, FestivalEntryStep $step): void
    {
        abort_unless($step->festival_entry_id === $entry->id, 404);
        $state = $this->forEntry($entry)->first(fn (array $state): bool => $state['step']->is($step));
        $postConfirmationPayment = $entry->status === FestivalEntryStatus::ChangesPending
            && in_array($step->status, [FestivalEntryStepStatus::Submitted, FestivalEntryStepStatus::ChangesRequested], true);

        abort_unless($state && $state['available'] && ($state['mutable'] || $postConfirmationPayment), 409, $state['locked_reason'] ?? __('app.festival_step_locked_previous'));
    }

    public function assertRequirementMutable(FestivalEntryRequirement $requirement): bool
    {
        $requirement->loadMissing(['definition.edition', 'entry.edition', 'entry.steps.workflowStep', 'entry.steps.requirements.definition.edition', 'entry.steps.requirements.submissions', 'entry.steps.charges', 'entryStep.workflowStep']);
        abort_unless($requirement->entryStep && $requirement->entryStep->festival_entry_id === $requirement->entry->id, 404);
        $state = $this->forEntry($requirement->entry)->first(fn (array $state): bool => $state['step']->is($requirement->entryStep));
        abort_unless($state, 409);

        if ($requirement->entry->status !== FestivalEntryStatus::ChangesPending
            && $state['mutable']
            && ! $this->deadlineResolver->dueAt($requirement->definition)?->isPast()) {
            return false;
        }

        if ($this->requirementIsPostConfirmationMutable($requirement->entry, $requirement)) {
            return true;
        }

        abort(409, __('app.festival_field_editing_unavailable'));
    }

    public function markPostConfirmationChange(FestivalEntryRequirement $requirement): void
    {
        $requirement->loadMissing(['entry', 'entryStep']);
        $requirement->entryStep->forceFill([
            'status' => FestivalEntryStepStatus::Submitted,
            'submitted_at' => now(),
            'reviewed_at' => null,
            'reviewed_by' => null,
            'review_notes' => null,
            'correction_due_at' => null,
        ])->save();
        $requirement->entry->forceFill(['status' => FestivalEntryStatus::ChangesPending])->save();
    }

    public function current(FestivalEntry $entry): ?FestivalEntryStep
    {
        return $this->forEntry($entry)->first(fn (array $state): bool => $state['available'] && $state['step']->status !== FestivalEntryStepStatus::Approved)['step'] ?? null;
    }

    private function requirementIsMutableForState(FestivalEntry $entry, FestivalEntryRequirement $requirement, bool $stepMutable): bool
    {
        if ($entry->status !== FestivalEntryStatus::ChangesPending
            && $stepMutable
            && ! $this->deadlineResolver->dueAt($requirement->definition)?->isPast()) {
            return true;
        }

        return $this->requirementIsPostConfirmationMutable($entry, $requirement);
    }

    private function requirementIsPostConfirmationMutable(FestivalEntry $entry, FestivalEntryRequirement $requirement): bool
    {
        if (! in_array($entry->status, [FestivalEntryStatus::Accepted, FestivalEntryStatus::ChangesPending], true)
            || $entry->registration_completed_at === null
            || ! $this->deadlineResolver->allowsPostConfirmationEdits($requirement->definition)) {
            return false;
        }

        $editableUntil = $this->deadlineResolver->editableUntil($requirement->definition);

        return $editableUntil !== null && ! $editableUntil->isPast();
    }
}

<?php

namespace App\Support\Festivals;

use App\Actions\Festivals\FestivalNotificationOutbox;
use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalNotificationType;
use App\Enums\FestivalQualificationStatus;
use App\Enums\FestivalWorkflowStepType;
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
        private readonly FestivalNotificationOutbox $notifications,
    ) {}

    /**
     * @return Collection<int, array{step: FestivalEntryStep, available: bool, mutable: bool, locked_reason: string|null, requirement_mutability: array<int, bool>, requirement_completeness: array<int, bool>, due_at: array<int, CarbonInterface|null>, editable_until: array<int, CarbonInterface|null>, requirements_complete: bool, has_blocking_charges: bool}>
     */
    public function forEntry(FestivalEntry $entry): Collection
    {
        $entry->loadMissing(['edition', 'steps.workflowStep', 'steps.requirements.definition.edition', 'steps.requirements.submissions', 'steps.charges']);
        $priorApproved = true;
        $states = collect();
        $chargesSettled = $entry->steps
            ->flatMap->charges
            ->every(fn ($charge): bool => in_array($charge->status, [FestivalChargeStatus::Paid, FestivalChargeStatus::Cancelled], true));
        $qualificationSettled = in_array($entry->qualification_status, [FestivalQualificationStatus::Passed, FestivalQualificationStatus::NotRequired], true);

        foreach ($entry->steps as $step) {
            $lockedReason = null;
            $summary = $step->workflowStep->type === FestivalWorkflowStepType::Summary;
            $terminal = in_array($entry->status, [FestivalEntryStatus::Rejected, FestivalEntryStatus::Withdrawn], true);
            $finalSummary = $summary && in_array($entry->status, [FestivalEntryStatus::Accepted, FestivalEntryStatus::ChangesPending, FestivalEntryStatus::Rejected], true);

            if ($terminal && ! $finalSummary) {
                $lockedReason = __('app.festival_entry_closed');
            } elseif (! $priorApproved && ! $finalSummary) {
                $lockedReason = __('app.festival_step_locked_previous');
            } elseif ($summary && ! $finalSummary && ! $chargesSettled) {
                $lockedReason = __('app.festival_full_confirm_payments_incomplete');
            } elseif ($summary && ! $finalSummary && ! $qualificationSettled) {
                $lockedReason = __('app.festival_full_confirm_qualification_incomplete');
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
            $mutable = ! $summary
                && ! $terminal
                && $available
                && in_array($step->status, [FestivalEntryStepStatus::Draft, FestivalEntryStepStatus::ChangesRequested], true);
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
        $requirement->loadMissing('entryStep');
        $entry = FestivalEntry::query()
            ->with(['account', 'edition', 'portalUser', 'category'])
            ->whereKey($requirement->festival_entry_id)
            ->lockForUpdate()
            ->firstOrFail();
        abort_unless(in_array($entry->status, [FestivalEntryStatus::Accepted, FestivalEntryStatus::ChangesPending], true), 409);
        $notifyOwner = $entry->status === FestivalEntryStatus::Accepted;
        $reviewCycle = $entry->reviewed_at?->format('U.u') ?? $entry->updated_at?->format('U.u') ?? (string) $entry->id;

        $requirement->entryStep->forceFill([
            'status' => FestivalEntryStepStatus::Submitted,
            'submitted_at' => now(),
            'reviewed_at' => null,
            'reviewed_by' => null,
            'review_notes' => null,
            'correction_due_at' => null,
        ])->save();
        $entry->forceFill(['status' => FestivalEntryStatus::ChangesPending])->save();
        $requirement->setRelation('entry', $entry);

        if ($notifyOwner) {
            $this->notifications->queueForEntry(
                $entry,
                FestivalNotificationType::EntrySubmitted,
                [
                    'entry_code' => $entry->code,
                    'status' => FestivalEntryStatus::ChangesPending->value,
                    'step' => $requirement->entryStep->workflowStep->title,
                    'requirement' => $requirement->definition->name,
                ],
                'post-confirmation-change:'.$entry->id.':'.$reviewCycle,
            );
        }
    }

    public function current(FestivalEntry $entry): ?FestivalEntryStep
    {
        return $this->forEntry($entry)->first(fn (array $state): bool => $state['available'] && $state['step']->status !== FestivalEntryStepStatus::Approved)['step'] ?? null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $states
     * @return Collection<int, array{requirement: FestivalEntryRequirement, step: FestivalEntryStep, state: array<string, mixed>}>
     */
    public function postConfirmationRequirements(Collection $states): Collection
    {
        return $states->flatMap(function (array $state): Collection {
            return $state['step']->requirements
                ->filter(fn (FestivalEntryRequirement $requirement): bool => ($state['editable_until'][$requirement->id] ?? null) !== null)
                ->map(fn (FestivalEntryRequirement $requirement): array => [
                    'requirement' => $requirement,
                    'step' => $state['step'],
                    'state' => $state,
                ]);
        })->values();
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

<?php

namespace App\Support\Festivals;

use App\Enums\FestivalEntryStepStatus;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryStep;
use Illuminate\Support\Collection;

class FestivalEntryWorkflowState
{
    /**
     * @return Collection<int, array{step: FestivalEntryStep, available: bool, mutable: bool, locked_reason: string|null}>
     */
    public function forEntry(FestivalEntry $entry): Collection
    {
        $entry->loadMissing('steps.workflowStep');
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
            $states->push(compact('step', 'available', 'mutable', 'lockedReason') + ['locked_reason' => $lockedReason]);
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

    public function current(FestivalEntry $entry): ?FestivalEntryStep
    {
        return $this->forEntry($entry)->first(fn (array $state): bool => $state['available'] && $state['step']->status !== FestivalEntryStepStatus::Approved)['step'] ?? null;
    }
}

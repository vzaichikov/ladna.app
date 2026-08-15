<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalWorkflowReviewEffect;
use App\Enums\FestivalWorkflowReviewMode;
use App\Enums\FestivalWorkflowStepType;
use App\Models\FestivalWorkflow;
use App\Models\FestivalWorkflowStep;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageFestivalWorkflowStep
{
    /** @param array<string, mixed> $data */
    public function create(FestivalWorkflow $workflow, array $data): FestivalWorkflowStep
    {
        return DB::transaction(function () use ($workflow, $data): FestivalWorkflowStep {
            $workflow = $this->lockWorkflow($workflow);
            $steps = $this->lockSteps($workflow);
            $this->assertSummaryMutationAllowed($steps, null, $data);

            $step = $workflow->steps()->create([
                'account_id' => $workflow->account_id,
                ...$this->normalizedData($data),
            ]);
            $this->normalizeOrder($this->lockSteps($workflow));

            return $step->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(FestivalWorkflowStep $step, array $data): FestivalWorkflowStep
    {
        return DB::transaction(function () use ($step, $data): FestivalWorkflowStep {
            $workflow = $this->lockWorkflow($step->workflow);
            $steps = $this->lockSteps($workflow);
            $this->assertExactlyOneSummary($steps);
            $step = $steps->firstWhere('id', $step->id) ?? abort(404);
            $this->assertSummaryMutationAllowed($steps, $step, $data);
            $step->update($this->normalizedData($data));
            $this->normalizeOrder($this->lockSteps($workflow));

            return $step->refresh();
        }, 3);
    }

    public function toggle(FestivalWorkflowStep $step): FestivalWorkflowStep
    {
        return DB::transaction(function () use ($step): FestivalWorkflowStep {
            $workflow = $this->lockWorkflow($step->workflow);
            $steps = $this->lockSteps($workflow);
            $this->assertExactlyOneSummary($steps);
            $step = $steps->firstWhere('id', $step->id) ?? abort(404);
            $this->assertOrdinaryStep($step);
            $step->update(['is_active' => ! $step->is_active]);

            return $step->refresh();
        }, 3);
    }

    public function move(FestivalWorkflowStep $step, string $direction): void
    {
        DB::transaction(function () use ($step, $direction): void {
            $workflow = $this->lockWorkflow($step->workflow);
            $steps = $this->lockSteps($workflow);
            $this->assertExactlyOneSummary($steps);
            $step = $steps->firstWhere('id', $step->id) ?? abort(404);
            $this->assertOrdinaryStep($step);
            $ordinarySteps = $steps
                ->reject(fn (FestivalWorkflowStep $candidate): bool => $candidate->type === FestivalWorkflowStepType::Summary)
                ->values();
            $index = $ordinarySteps->search(fn (FestivalWorkflowStep $candidate): bool => $candidate->is($step));
            abort_unless($index !== false, 404);
            $targetIndex = $direction === 'up' ? $index - 1 : $index + 1;

            if ($ordinarySteps->has($targetIndex)) {
                $target = $ordinarySteps[$targetIndex];
                $currentOrder = $step->sort_order;
                $step->forceFill(['sort_order' => $target->sort_order])->save();
                $target->forceFill(['sort_order' => $currentOrder])->save();
            }

            $this->normalizeOrder($this->lockSteps($workflow));
        }, 3);
    }

    public function delete(FestivalWorkflowStep $step): void
    {
        DB::transaction(function () use ($step): void {
            $workflow = $this->lockWorkflow($step->workflow);
            $steps = $this->lockSteps($workflow);
            $this->assertExactlyOneSummary($steps);
            $step = $steps->firstWhere('id', $step->id) ?? abort(404);
            $this->assertOrdinaryStep($step);

            if ($step->entrySteps()->exists() || $step->requirementDefinitions()->exists() || $step->chargeDefinitions()->exists()) {
                throw ValidationException::withMessages(['festival_workflow_step' => __('app.festival_workflow_step_dependency_block')]);
            }

            $step->delete();
            $this->normalizeOrder($this->lockSteps($workflow));
        }, 3);
    }

    private function lockWorkflow(FestivalWorkflow $workflow): FestivalWorkflow
    {
        return FestivalWorkflow::query()->whereKey($workflow->id)->lockForUpdate()->firstOrFail();
    }

    /** @return Collection<int, FestivalWorkflowStep> */
    private function lockSteps(FestivalWorkflow $workflow): Collection
    {
        return FestivalWorkflowStep::query()
            ->where('festival_workflow_id', $workflow->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** @param array<string, mixed> $data */
    private function assertSummaryMutationAllowed(Collection $steps, ?FestivalWorkflowStep $step, array $data): void
    {
        $summarySteps = $steps->filter(fn (FestivalWorkflowStep $candidate): bool => $candidate->type === FestivalWorkflowStepType::Summary);
        $requestedSummary = ($data['type'] ?? null) === FestivalWorkflowStepType::Summary->value;

        if ($step || ! $requestedSummary) {
            $this->assertExactlyOneSummary($steps);
        }

        if ($step?->type === FestivalWorkflowStepType::Summary
            && (! $requestedSummary || ! ($data['is_active'] ?? false))) {
            throw ValidationException::withMessages(['type' => __('app.festival_summary_step_protected')]);
        }

        if ($requestedSummary && $summarySteps->contains(fn (FestivalWorkflowStep $candidate): bool => ! $step || ! $candidate->is($step))) {
            throw ValidationException::withMessages(['type' => __('app.festival_summary_step_unique')]);
        }
    }

    /** @param Collection<int, FestivalWorkflowStep> $steps */
    private function assertExactlyOneSummary(Collection $steps): void
    {
        if ($steps->where('type', FestivalWorkflowStepType::Summary)->count() !== 1) {
            throw ValidationException::withMessages(['type' => __('app.festival_summary_step_required')]);
        }
    }

    private function assertOrdinaryStep(FestivalWorkflowStep $step): void
    {
        if ($step->type === FestivalWorkflowStepType::Summary) {
            throw ValidationException::withMessages(['festival_workflow_step' => __('app.festival_summary_step_protected')]);
        }
    }

    /** @param array<string, mixed> $data */
    private function normalizedData(array $data): array
    {
        $data['is_active'] = $data['is_active'] ?? false;

        if (($data['type'] ?? null) === FestivalWorkflowStepType::Summary->value) {
            $data['review_mode'] = FestivalWorkflowReviewMode::Automatic->value;
            $data['review_effect'] = FestivalWorkflowReviewEffect::None->value;
            $data['opens_at'] = null;
            $data['due_at'] = null;
            $data['is_active'] = true;
        }

        return $data;
    }

    /** @param Collection<int, FestivalWorkflowStep> $steps */
    private function normalizeOrder(Collection $steps): void
    {
        $ordered = $steps
            ->reject(fn (FestivalWorkflowStep $step): bool => $step->type === FestivalWorkflowStepType::Summary)
            ->concat($steps->filter(fn (FestivalWorkflowStep $step): bool => $step->type === FestivalWorkflowStepType::Summary));

        foreach ($ordered->values() as $index => $step) {
            $step->forceFill(['sort_order' => ($index + 1) * 10])->save();
        }
    }
}

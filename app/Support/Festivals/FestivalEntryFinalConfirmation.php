<?php

namespace App\Support\Festivals;

use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalQualificationStatus;
use App\Enums\FestivalWorkflowStepType;
use App\Models\FestivalCategory;
use App\Models\FestivalCharge;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryStep;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class FestivalEntryFinalConfirmation
{
    public function __construct(private readonly FestivalEntryStepCompletion $completion) {}

    public function prepare(FestivalEntry $entry): void
    {
        $entry->loadMissing([
            'category',
            'steps.workflowStep',
            'steps.requirements.definition',
            'steps.requirements.selectedHelpers',
            'steps.requirements.submissions',
            'steps.charges',
            'charges',
        ]);
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public function blockers(FestivalEntry $entry, ?FestivalCategory $category = null): array
    {
        $evaluation = $this->evaluate($entry, $category);
        $blockers = [];

        if (! $evaluation['summary_valid']) {
            $blockers[] = [
                'label' => __('app.festival_final_confirmation_check_summary'),
                'value' => __('app.festival_full_confirm_summary_invalid'),
            ];
        }

        foreach ($evaluation['invalid_steps'] as $step) {
            $blockers[] = [
                'label' => $step->workflowStep->title,
                'value' => __('app.festival_step_status_'.$step->status->value),
            ];
        }

        foreach ($evaluation['changed_step_checks'] as $check) {
            if (! $check['requirements_complete']) {
                $blockers[] = [
                    'label' => $check['step']->workflowStep->title,
                    'value' => __('app.festival_step_requirements_incomplete'),
                ];
            }

            if (! $check['charges_complete']) {
                $blockers[] = [
                    'label' => $check['step']->workflowStep->title,
                    'value' => __('app.festival_step_payment_required'),
                ];
            }
        }

        if (! $evaluation['qualification_ready']) {
            $blockers[] = [
                'label' => __('app.festival_final_confirmation_check_qualification'),
                'value' => __('app.festival_full_confirm_qualification_incomplete'),
            ];
        }

        if (! $evaluation['payments_ready']) {
            $blockers[] = [
                'label' => __('app.festival_final_confirmation_check_payments'),
                'value' => __('app.festival_full_confirm_payments_incomplete'),
            ];
        }

        if (! $evaluation['capacity_available']) {
            $blockers[] = [
                'label' => __('app.festival_category'),
                'value' => __('app.festival_category_full'),
            ];
        }

        return $blockers;
    }

    /** @return Collection<int, FestivalEntryStep> */
    public function assertReady(FestivalEntry $entry, ?FestivalCategory $category = null): Collection
    {
        $evaluation = $this->evaluate($entry, $category);

        if (! $evaluation['summary_valid']) {
            throw ValidationException::withMessages(['festival_application' => __('app.festival_full_confirm_summary_invalid')]);
        }

        if ($evaluation['invalid_steps']->isNotEmpty()) {
            throw ValidationException::withMessages(['festival_application' => __('app.festival_full_confirm_steps_incomplete')]);
        }

        foreach ($evaluation['changed_step_checks'] as $check) {
            if (! $check['requirements_complete']) {
                throw ValidationException::withMessages(['festival_application' => __('app.festival_step_requirements_incomplete')]);
            }

            if (! $check['charges_complete']) {
                throw ValidationException::withMessages(['festival_application' => __('app.festival_step_payment_required')]);
            }
        }

        if (! $evaluation['qualification_ready']) {
            throw ValidationException::withMessages(['festival_application' => __('app.festival_full_confirm_qualification_incomplete')]);
        }

        if (! $evaluation['payments_ready']) {
            throw ValidationException::withMessages(['festival_application' => __('app.festival_full_confirm_payments_incomplete')]);
        }

        if (! $evaluation['capacity_available']) {
            throw ValidationException::withMessages(['festival_category_id' => __('app.festival_category_full')]);
        }

        return $evaluation['changed_steps'];
    }

    /**
     * @return array{
     *     summary_valid: bool,
     *     invalid_steps: Collection<int, FestivalEntryStep>,
     *     changed_steps: Collection<int, FestivalEntryStep>,
     *     changed_step_checks: array<int, array{step: FestivalEntryStep, requirements_complete: bool, charges_complete: bool}>,
     *     qualification_ready: bool,
     *     payments_ready: bool,
     *     capacity_available: bool
     * }
     */
    private function evaluate(FestivalEntry $entry, ?FestivalCategory $category): array
    {
        $this->prepare($entry);
        $category ??= $entry->category;
        $steps = $entry->steps;
        $summarySteps = $steps->filter(
            fn (FestivalEntryStep $step): bool => $step->workflowStep->type === FestivalWorkflowStepType::Summary,
        );
        $summary = $summarySteps->count() === 1 ? $summarySteps->first() : null;
        $registrationSteps = $summary
            ? $steps->reject(fn (FestivalEntryStep $step): bool => $step->is($summary))
            : collect();
        $changedSteps = collect();

        if ($entry->status === FestivalEntryStatus::ChangesPending) {
            $invalidSteps = $registrationSteps->reject(
                fn (FestivalEntryStep $step): bool => in_array(
                    $step->status,
                    [FestivalEntryStepStatus::Approved, FestivalEntryStepStatus::Submitted],
                    true,
                ),
            );
            $changedSteps = $registrationSteps->where('status', FestivalEntryStepStatus::Submitted);
        } else {
            $invalidSteps = $registrationSteps->reject(
                fn (FestivalEntryStep $step): bool => $step->status === FestivalEntryStepStatus::Approved,
            );
        }

        $changedStepChecks = $changedSteps
            ->map(fn (FestivalEntryStep $step): array => [
                'step' => $step,
                'requirements_complete' => $this->completion->requirementsComplete($step),
                'charges_complete' => $this->completion->chargesComplete($step),
            ])
            ->values()
            ->all();

        return [
            'summary_valid' => $summary !== null,
            'invalid_steps' => $invalidSteps->values(),
            'changed_steps' => $changedSteps->values(),
            'changed_step_checks' => $changedStepChecks,
            'qualification_ready' => in_array(
                $entry->qualification_status,
                [FestivalQualificationStatus::Passed, FestivalQualificationStatus::NotRequired],
                true,
            ),
            'payments_ready' => $entry->charges->every(
                fn (FestivalCharge $charge): bool => in_array(
                    $charge->status,
                    [FestivalChargeStatus::Paid, FestivalChargeStatus::Cancelled],
                    true,
                ),
            ),
            'capacity_available' => ! $category->applicationCapacityReached(excludingEntry: $entry),
        ];
    }
}

<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalChargeDuePolicy;
use App\Enums\FestivalFieldScope;
use App\Enums\FestivalRequirementStatus;
use App\Enums\FestivalWorkflowReviewEffect;
use App\Models\FestivalCategory;
use App\Models\FestivalChargeDefinition;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryStep;
use App\Models\FestivalRequirementDefinition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InitializeFestivalEntryWorkflow
{
    public function __construct(
        private readonly ProvisionFestivalWorkflow $provisionWorkflow,
        private readonly FestivalChargeDefinitionResolver $chargeResolver,
    ) {}

    public function execute(FestivalEntry $entry): FestivalEntry
    {
        return DB::transaction(function () use ($entry): FestivalEntry {
            $entry = FestivalEntry::query()->with(['account', 'edition', 'category.direction', 'category.registrationWorkflow.steps', 'participants', 'portalUser'])->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($entry->steps()->exists()) {
                return $entry->load(['steps.workflowStep', 'steps.requirements.definition', 'steps.requirements.participant', 'steps.requirements.submissions', 'steps.charges']);
            }

            $category = FestivalCategory::query()
                ->with(['direction', 'registrationWorkflow.steps'])
                ->whereKey($entry->festival_category_id)
                ->where('account_id', $entry->account_id)
                ->where('festival_edition_id', $entry->festival_edition_id)
                ->lockForUpdate()
                ->firstOrFail();
            $entry->setRelation('category', $category);
            $workflow = $category->registrationWorkflow;
            if (! $workflow) {
                $workflow = $this->provisionWorkflow->execute($entry->edition, __('app.festival_standard_workflow'));
                $entry->category->forceFill(['festival_workflow_id' => $workflow->id])->save();
            }

            $workflow->loadMissing('steps');
            $runtimeSteps = collect();
            foreach ($workflow->steps->where('is_active', true) as $step) {
                $runtimeStep = $entry->steps()->create([
                    'account_id' => $entry->account_id,
                    'festival_workflow_step_id' => $step->id,
                ]);
                $runtimeStep->setRelation('workflowStep', $step);
                $runtimeSteps->put($step->id, $runtimeStep);
            }

            $this->createRequirements($entry, $runtimeSteps);
            $hasQualification = $workflow->steps->contains(fn ($step): bool => $step->review_effect === FestivalWorkflowReviewEffect::Qualification);
            $this->createCharges($entry, $runtimeSteps, $hasQualification);

            return $entry->refresh()->load(['steps.workflowStep', 'steps.requirements.definition', 'steps.requirements.participant', 'steps.requirements.submissions', 'steps.charges', 'participants', 'edition', 'category.direction']);
        }, 3);
    }

    /** @param Collection<int, FestivalEntryStep> $runtimeSteps */
    private function createRequirements(FestivalEntry $entry, Collection $runtimeSteps): void
    {
        $definitions = FestivalRequirementDefinition::query()
            ->where('festival_edition_id', $entry->festival_edition_id)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('festival_category_id')->orWhere('festival_category_id', $entry->festival_category_id))
            ->orderBy('sort_order')
            ->lockForUpdate()
            ->get();

        foreach ($definitions as $definition) {
            $step = $runtimeSteps->get($definition->festival_workflow_step_id);
            if (! $step && $definition->festival_workflow_step_id !== null) {
                continue;
            }
            $step ??= $runtimeSteps->first(fn (FestivalEntryStep $candidate): bool => $candidate->workflowStep->code === ($definition->stage === 'qualification' ? 'application' : 'technical_form'))
                ?? $runtimeSteps->first();

            foreach ($this->subjects($entry, $definition->subject_scope) as $subject) {
                $entry->requirements()->create([
                    'account_id' => $entry->account_id,
                    'festival_entry_step_id' => $step?->id,
                    'festival_requirement_definition_id' => $definition->id,
                    'festival_participant_id' => $subject['participant_id'],
                    'subject_key' => $subject['key'],
                    'status' => FestivalRequirementStatus::Missing->value,
                ]);
            }
        }
    }

    /** @return array<int, array{key: string, participant_id: int|null}> */
    private function subjects(FestivalEntry $entry, FestivalFieldScope $scope): array
    {
        if ($scope === FestivalFieldScope::Participant) {
            return $entry->participants->map(fn ($participant): array => [
                'key' => 'participant:'.$participant->id,
                'participant_id' => $participant->id,
            ])->values()->all();
        }

        if ($scope === FestivalFieldScope::Registrant) {
            return [['key' => 'registrant:'.$entry->festival_portal_user_id, 'participant_id' => null]];
        }

        return [['key' => 'entry', 'participant_id' => null]];
    }

    /** @param Collection<int, FestivalEntryStep> $runtimeSteps */
    private function createCharges(FestivalEntry $entry, Collection $runtimeSteps, bool $hasQualification): void
    {
        $definitions = FestivalChargeDefinition::query()
            ->where('festival_edition_id', $entry->festival_edition_id)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('festival_category_id')->orWhere('festival_category_id', $entry->festival_category_id))
            ->lockForUpdate()
            ->get();

        foreach ($definitions as $definition) {
            if ($hasQualification && $definition->kind === 'participation' && $definition->due_policy === FestivalChargeDuePolicy::ApprovalRelative) {
                continue;
            }

            $fallbackCode = match ($definition->kind) {
                'qualification' => 'application',
                'participation' => 'participation_payment',
                default => 'technical_form',
            };
            $step = $runtimeSteps->get($definition->festival_workflow_step_id);
            if (! $step && $definition->festival_workflow_step_id !== null) {
                continue;
            }
            $step ??= $runtimeSteps->first(fn (FestivalEntryStep $candidate): bool => $candidate->workflowStep->code === $fallbackCode)
                ?? $runtimeSteps->first();

            $amount = $this->chargeResolver->amount($definition, $entry);
            $entry->charges()->create([
                'account_id' => $entry->account_id,
                'festival_entry_step_id' => $step?->id,
                'festival_charge_definition_id' => $definition->id,
                'code' => 'FCH-'.str()->upper(str()->random(12)),
                'kind' => $definition->kind,
                'name' => $definition->name,
                'amount_cents' => $amount,
                'currency' => strtoupper($entry->account->default_currency),
                'due_at' => $this->chargeResolver->dueAt($definition),
                'status' => $amount === 0 ? 'paid' : 'pending',
                'paid_at' => $amount === 0 ? now() : null,
            ]);
        }
    }
}

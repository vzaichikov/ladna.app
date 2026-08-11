<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalFieldScope;
use App\Enums\FestivalRequirementStatus;
use App\Models\FestivalCategory;
use App\Models\FestivalChargeDefinition;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryStep;
use App\Models\FestivalRequirementDefinition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InitializeFestivalEntryWorkflow
{
    public function __construct(private readonly ProvisionFestivalWorkflow $provisionWorkflow) {}

    public function execute(FestivalEntry $entry): FestivalEntry
    {
        return DB::transaction(function () use ($entry): FestivalEntry {
            $entry = FestivalEntry::query()->with(['edition', 'category.direction', 'category.registrationWorkflow.steps', 'participants', 'portalUser'])->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($entry->steps()->exists()) {
                return $entry->load(['steps.requirements.submissions', 'steps.charges']);
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
                $runtimeSteps->put($step->id, $entry->steps()->create([
                    'account_id' => $entry->account_id,
                    'festival_workflow_step_id' => $step->id,
                    'code' => $step->code,
                    'type' => $step->type,
                    'title' => $step->title,
                    'description' => $step->description,
                    'sort_order' => $step->sort_order,
                    'review_mode' => $step->review_mode,
                    'review_effect' => $step->review_effect,
                    'opens_at' => $step->opens_at,
                    'due_at' => $step->due_at,
                    'step_snapshot' => $step->only(['code', 'type', 'title', 'description', 'sort_order', 'review_mode', 'review_effect', 'opens_at', 'due_at', 'config']),
                ]));
            }

            $entry->forceFill([
                'registrant_snapshot' => [
                    'portal_user_id' => $entry->portalUser->id,
                    'registrant_type' => $entry->portalUser->registrant_type?->value ?? $entry->portalUser->registrant_type,
                    'name' => $entry->portalUser->displayName(),
                    'email' => $entry->portalUser->email,
                    'phone' => $entry->portalUser->phone,
                    'city' => $entry->portalUser->city,
                    'studio_name' => $entry->portalUser->studio_name,
                ],
                'workflow_snapshot' => [
                    'workflow_id' => $workflow->id,
                    'name' => $workflow->name,
                    'steps' => $workflow->steps->map->only(['id', 'code', 'type', 'title', 'description', 'sort_order', 'review_mode', 'review_effect', 'opens_at', 'due_at', 'config'])->values()->all(),
                ],
            ])->save();

            $this->createRequirements($entry, $runtimeSteps);
            $this->createCharges($entry, $runtimeSteps);

            return $entry->refresh()->load(['steps.requirements.submissions', 'steps.charges', 'participants', 'edition', 'category.direction']);
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
            $step = $runtimeSteps->get($definition->festival_workflow_step_id)
                ?? $runtimeSteps->first(fn (FestivalEntryStep $candidate): bool => $candidate->code === ($definition->stage === 'qualification' ? 'application' : 'technical_form'))
                ?? $runtimeSteps->first();

            foreach ($this->subjects($entry, $definition->subject_scope) as $subject) {
                $entry->requirements()->create([
                    'account_id' => $entry->account_id,
                    'festival_entry_step_id' => $step?->id,
                    'festival_requirement_definition_id' => $definition->id,
                    'festival_participant_id' => $subject['participant_id'],
                    'subject_scope' => $definition->subject_scope,
                    'subject_key' => $subject['key'],
                    'definition_snapshot' => [
                        'code' => $definition->code,
                        'type' => $definition->type->value,
                        'input_type' => $definition->input_type->value,
                        'subject_scope' => $definition->subject_scope->value,
                        'subject_label' => $subject['label'],
                        'name' => $definition->name,
                        'instructions' => $definition->instructions,
                        'options' => $definition->options,
                        'validation' => $definition->validation,
                        'pricing' => $definition->pricing,
                        'allowed_extensions' => $definition->allowed_extensions,
                        'allowed_mime_types' => $definition->allowed_mime_types,
                        'max_size_kb' => $definition->max_size_kb,
                        'min_duration_seconds' => $definition->min_duration_seconds,
                        'max_duration_seconds' => $definition->max_duration_seconds,
                        'is_required' => $definition->is_required,
                    ],
                    'due_at' => $definition->due_at,
                    'is_required' => $definition->is_required,
                    'status' => FestivalRequirementStatus::Missing->value,
                ]);
            }
        }
    }

    /** @return array<int, array{key: string, label: string, participant_id: int|null}> */
    private function subjects(FestivalEntry $entry, FestivalFieldScope $scope): array
    {
        if ($scope === FestivalFieldScope::Participant) {
            return $entry->participants->map(fn ($participant): array => [
                'key' => 'participant:'.$participant->id,
                'label' => $participant->displayName(),
                'participant_id' => $participant->id,
            ])->values()->all();
        }

        if ($scope === FestivalFieldScope::Registrant) {
            return [['key' => 'registrant:'.$entry->festival_portal_user_id, 'label' => $entry->portalUser->displayName(), 'participant_id' => null]];
        }

        return [['key' => 'entry', 'label' => $entry->entry_name, 'participant_id' => null]];
    }

    /** @param Collection<int, FestivalEntryStep> $runtimeSteps */
    private function createCharges(FestivalEntry $entry, Collection $runtimeSteps): void
    {
        $definitions = FestivalChargeDefinition::query()
            ->where('festival_edition_id', $entry->festival_edition_id)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('festival_category_id')->orWhere('festival_category_id', $entry->festival_category_id))
            ->lockForUpdate()
            ->get();

        foreach ($definitions as $definition) {
            $fallbackCode = match ($definition->kind) {
                'qualification' => 'application',
                'participation' => 'participation_payment',
                default => 'technical_form',
            };
            $step = $runtimeSteps->get($definition->festival_workflow_step_id)
                ?? $runtimeSteps->first(fn (FestivalEntryStep $candidate): bool => $candidate->code === $fallbackCode)
                ?? $runtimeSteps->first();

            $entry->charges()->create([
                'account_id' => $entry->account_id,
                'festival_entry_step_id' => $step?->id,
                'festival_charge_definition_id' => $definition->id,
                'code' => 'FCH-'.str()->upper(str()->random(12)),
                'kind' => $definition->kind,
                'name' => $definition->name,
                'amount_cents' => $definition->amount_cents,
                'currency' => $definition->currency,
                'due_at' => $definition->due_at,
                'status' => $definition->amount_cents === 0 ? 'paid' : 'pending',
                'paid_at' => $definition->amount_cents === 0 ? now() : null,
                'definition_snapshot' => $definition->only(['name', 'kind', 'amount_cents', 'currency']),
            ]);
        }
    }
}

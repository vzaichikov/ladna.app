<?php

namespace App\Actions\Festivals;

use App\Enums\AccountStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalFieldScope;
use App\Enums\FestivalRequirementStatus;
use App\Enums\FestivalWorkflowStepType;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalEntryStep;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalWorkflowStep;
use App\Support\Festivals\FestivalRequirementDeadlineResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AttachFestivalEntryRequirements
{
    /** @var list<string> */
    private const ActiveEntryStatuses = [
        FestivalEntryStatus::Draft->value,
        FestivalEntryStatus::Submitted->value,
        FestivalEntryStatus::UnderReview->value,
        FestivalEntryStatus::ChangesPending->value,
        FestivalEntryStatus::Accepted->value,
    ];

    public function __construct(private readonly FestivalRequirementDeadlineResolver $deadlineResolver) {}

    /**
     * @param  list<int>  $fieldIds
     * @return array<string, mixed>
     */
    public function preview(int $accountId, int $editionId, int $workflowStepId, array $fieldIds): array
    {
        return $this->report($this->target($accountId, $editionId, $workflowStepId, $fieldIds));
    }

    /**
     * @param  list<int>  $fieldIds
     * @return array<string, mixed>
     */
    public function execute(
        int $accountId,
        int $editionId,
        int $workflowStepId,
        array $fieldIds,
        int $expectedMissing,
    ): array {
        return DB::transaction(function () use ($accountId, $editionId, $workflowStepId, $fieldIds, $expectedMissing): array {
            $target = $this->target($accountId, $editionId, $workflowStepId, $fieldIds, lock: true);
            $missing = $target['missing'];

            if ($missing->count() !== $expectedMissing) {
                throw new RuntimeException(sprintf(
                    'The live missing-row count is %d, not the expected %d. Run the dry run again before executing.',
                    $missing->count(),
                    $expectedMissing,
                ));
            }

            $inserted = 0;
            foreach ($missing as $row) {
                $requirement = FestivalEntryRequirement::query()->firstOrCreate(
                    [
                        'festival_entry_id' => $row['festival_entry_id'],
                        'festival_requirement_definition_id' => $row['festival_requirement_definition_id'],
                        'subject_key' => 'entry',
                    ],
                    [
                        'account_id' => $accountId,
                        'festival_entry_step_id' => $row['festival_entry_step_id'],
                        'festival_participant_id' => null,
                        'status' => FestivalRequirementStatus::Missing->value,
                    ],
                );

                if ($requirement->wasRecentlyCreated) {
                    $inserted++;
                }
            }

            if ($inserted !== $missing->count()) {
                throw new RuntimeException('A concurrent write changed the requirement set. The transaction was rolled back; run the dry run again.');
            }

            $verified = $this->target($accountId, $editionId, $workflowStepId, $fieldIds, lock: true);
            if ($verified['missing']->isNotEmpty()) {
                throw new RuntimeException('Post-write verification found missing requirement rows. The transaction was rolled back.');
            }

            return $this->report($verified) + ['inserted_rows' => $inserted];
        }, 3);
    }

    /**
     * @param  list<int>  $fieldIds
     * @return array{
     *     account: Account,
     *     edition: FestivalEdition,
     *     workflow_step: FestivalWorkflowStep,
     *     definitions: EloquentCollection<int, FestivalRequirementDefinition>,
     *     entries: EloquentCollection<int, FestivalEntry>,
     *     entry_steps: EloquentCollection<int, FestivalEntryStep>,
     *     existing: EloquentCollection<int, FestivalEntryRequirement>,
     *     missing: Collection<int, array{festival_entry_id: int, festival_entry_step_id: int, festival_requirement_definition_id: int}>
     * }
     */
    private function target(int $accountId, int $editionId, int $workflowStepId, array $fieldIds, bool $lock = false): array
    {
        $this->assertIds($accountId, $editionId, $workflowStepId, $fieldIds);

        $account = Account::query()->find($accountId);
        if (! $account || $account->status !== AccountStatus::Active || ! $account->enable_festivals) {
            throw new RuntimeException("Account #{$accountId} is not an active Festival-enabled account.");
        }

        $editionQuery = FestivalEdition::query()
            ->whereKey($editionId)
            ->where('account_id', $accountId);
        if ($lock) {
            $editionQuery->lockForUpdate();
        }
        $edition = $editionQuery->first();
        if (! $edition) {
            throw new RuntimeException("Festival edition #{$editionId} does not belong to account #{$accountId}.");
        }
        if (! $edition->registrationIsOpen()) {
            throw new RuntimeException("Festival edition #{$editionId} does not currently have open registration.");
        }

        $entriesQuery = FestivalEntry::query()
            ->where('account_id', $accountId)
            ->where('festival_edition_id', $editionId)
            ->whereIn('status', self::ActiveEntryStatuses)
            ->orderBy('id');
        if ($lock) {
            $entriesQuery->lockForUpdate();
        }
        $activeEntries = $entriesQuery->get();

        $entryStepsQuery = FestivalEntryStep::query()
            ->whereIn('festival_entry_id', $activeEntries->modelKeys())
            ->where('festival_workflow_step_id', $workflowStepId)
            ->orderBy('id');
        if ($lock) {
            $entryStepsQuery->lockForUpdate();
        }
        $entrySteps = $entryStepsQuery->get();
        $duplicateEntryId = $entrySteps->groupBy('festival_entry_id')->first(fn (Collection $steps): bool => $steps->count() !== 1)?->first()?->festival_entry_id;
        if ($duplicateEntryId !== null) {
            throw new RuntimeException("Application #{$duplicateEntryId} has more than one runtime row for workflow step #{$workflowStepId}.");
        }

        $targetEntryIds = $entrySteps->pluck('festival_entry_id');
        $entries = $activeEntries->whereIn('id', $targetEntryIds)->values();

        $workflowStepQuery = FestivalWorkflowStep::query()
            ->with('workflow')
            ->whereKey($workflowStepId)
            ->where('account_id', $accountId);
        if ($lock) {
            $workflowStepQuery->lockForUpdate();
        }
        $workflowStep = $workflowStepQuery->first();
        if (! $workflowStep
            || ! $workflowStep->workflow
            || $workflowStep->workflow->account_id !== $accountId
            || $workflowStep->workflow->festival_edition_id !== $editionId
            || ! $workflowStep->workflow->is_active
            || ! $workflowStep->is_active
            || $workflowStep->type !== FestivalWorkflowStepType::Form) {
            throw new RuntimeException("Workflow step #{$workflowStepId} is not the active form step for Festival edition #{$editionId}.");
        }

        $definitionsQuery = FestivalRequirementDefinition::query()
            ->whereKey($fieldIds)
            ->orderBy('id');
        if ($lock) {
            $definitionsQuery->lockForUpdate();
        }
        $definitions = $definitionsQuery->get();
        $missingFieldIds = array_values(array_diff($fieldIds, $definitions->modelKeys()));
        if ($missingFieldIds !== []) {
            throw new RuntimeException('Registration fields do not exist: '.implode(', ', $missingFieldIds).'.');
        }

        foreach ($definitions as $definition) {
            $this->assertDefinition($definition, $accountId, $edition, $workflowStepId);
        }

        $existingQuery = FestivalEntryRequirement::query()
            ->whereIn('festival_entry_id', $targetEntryIds)
            ->whereIn('festival_requirement_definition_id', $fieldIds)
            ->orderBy('id');
        if ($lock) {
            $existingQuery->lockForUpdate();
        }
        $existing = $existingQuery->get();
        $stepIdsByEntry = $entrySteps->pluck('id', 'festival_entry_id');

        foreach ($existing as $requirement) {
            $expectedStepId = $stepIdsByEntry->get($requirement->festival_entry_id);
            if ($requirement->account_id !== $accountId
                || $requirement->festival_entry_step_id !== $expectedStepId
                || $requirement->subject_key !== 'entry'
                || $requirement->festival_participant_id !== null) {
                throw new RuntimeException("Requirement row #{$requirement->id} is linked outside the exact entry/Music-step scope.");
            }
        }

        $existingKeys = $existing->mapWithKeys(fn (FestivalEntryRequirement $requirement): array => [
            $requirement->festival_entry_id.':'.$requirement->festival_requirement_definition_id => true,
        ]);
        $missing = $entries->flatMap(function (FestivalEntry $entry) use ($definitions, $existingKeys, $stepIdsByEntry): Collection {
            return $definitions
                ->reject(fn (FestivalRequirementDefinition $definition): bool => $existingKeys->has($entry->id.':'.$definition->id))
                ->map(fn (FestivalRequirementDefinition $definition): array => [
                    'festival_entry_id' => $entry->id,
                    'festival_entry_step_id' => $stepIdsByEntry->get($entry->id),
                    'festival_requirement_definition_id' => $definition->id,
                ]);
        })->values();

        return [
            'account' => $account,
            'edition' => $edition,
            'workflow_step' => $workflowStep,
            'definitions' => $definitions,
            'entries' => $entries,
            'entry_steps' => $entrySteps,
            'existing' => $existing,
            'missing' => $missing,
        ];
    }

    /** @param list<int> $fieldIds */
    private function assertIds(int $accountId, int $editionId, int $workflowStepId, array $fieldIds): void
    {
        if ($accountId < 1 || $editionId < 1 || $workflowStepId < 1) {
            throw new RuntimeException('Account, edition, and workflow-step IDs must be positive integers.');
        }

        if (count($fieldIds) !== 5 || count(array_unique($fieldIds)) !== 5 || collect($fieldIds)->contains(fn (int $id): bool => $id < 1)) {
            throw new RuntimeException('Exactly five distinct positive registration field IDs are required.');
        }
    }

    private function assertDefinition(
        FestivalRequirementDefinition $definition,
        int $accountId,
        FestivalEdition $edition,
        int $workflowStepId,
    ): void {
        $editableRule = $this->deadlineResolver->rule($definition, 'editable_until_rule');
        $editableUntil = $this->deadlineResolver->editableUntil($definition);
        $valid = $definition->account_id === $accountId
            && $definition->festival_edition_id === $edition->id
            && $definition->festival_category_id === null
            && $definition->festival_workflow_step_id === $workflowStepId
            && $definition->subject_scope === FestivalFieldScope::Entry
            && $definition->is_active
            && $this->deadlineResolver->allowsPostConfirmationEdits($definition)
            && $editableRule === [
                'reference' => FestivalRequirementDeadlineResolver::RegistrationClosesAt,
                'offset_days' => 0,
            ]
            && $editableUntil !== null
            && ! $editableUntil->isPast();

        if (! $valid) {
            throw new RuntimeException("Registration field #{$definition->id} is not an active, global, entry-scoped field on the exact Music step with post-confirmation editing through registration close.");
        }
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function report(array $target): array
    {
        /** @var Account $account */
        $account = $target['account'];
        /** @var FestivalEdition $edition */
        $edition = $target['edition'];
        /** @var FestivalWorkflowStep $workflowStep */
        $workflowStep = $target['workflow_step'];
        /** @var EloquentCollection<int, FestivalRequirementDefinition> $definitions */
        $definitions = $target['definitions'];
        /** @var EloquentCollection<int, FestivalEntry> $entries */
        $entries = $target['entries'];

        return [
            'account_id' => $account->id,
            'account_name' => $account->name,
            'edition_id' => $edition->id,
            'edition_slug' => $edition->slug,
            'edition_title' => $edition->title,
            'workflow_step_id' => $workflowStep->id,
            'workflow_step_code' => $workflowStep->code,
            'workflow_step_title' => $workflowStep->title,
            'application_count' => $entries->count(),
            'application_statuses' => $entries->countBy(fn (FestivalEntry $entry): string => $entry->status->value)->sortKeys()->all(),
            'field_count' => $definitions->count(),
            'existing_rows' => $target['existing']->count(),
            'missing_rows' => $target['missing']->count(),
            'fields' => $definitions->map(fn (FestivalRequirementDefinition $definition): array => [
                'id' => $definition->id,
                'code' => $definition->code,
                'name' => $definition->name,
                'input_type' => $definition->input_type->value,
                'required' => $definition->is_required,
                'extensions' => $definition->allowed_extensions ?? [],
                'mime_types' => $definition->allowed_mime_types ?? [],
                'max_size_kb' => $definition->max_size_kb,
            ])->all(),
        ];
    }
}

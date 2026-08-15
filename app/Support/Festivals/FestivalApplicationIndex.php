<?php

namespace App\Support\Festivals;

use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalRequirementInputType;
use App\Enums\FestivalRequirementStatus;
use App\Models\FestivalCategory;
use App\Models\FestivalCharge;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalEntryStep;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalSubmission;
use App\Models\FestivalWorkflow;
use App\Models\FestivalWorkflowStep;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FestivalApplicationIndex
{
    public const QueueAwaitingReview = 'awaiting_review';

    public const QueueCorrectionsRequested = 'corrections_requested';

    public const QueuePaymentIncomplete = 'payment_incomplete';

    public const QueueNotSubmitted = 'not_submitted';

    public const QueueComplete = 'complete';

    public const QueueClosed = 'closed';

    public const ChecklistOpen = 'open';

    public const ChecklistComplete = 'complete';

    public const PaymentIncomplete = 'incomplete';

    public const PaymentPaid = 'paid';

    public const PaymentNotRequired = 'not_required';

    /**
     * @return array{
     *     categories: Collection<int, FestivalCategory>,
     *     current_steps: Collection<int, FestivalWorkflowStep>,
     *     filters: array{q: string, status: string, category: string, queue: string, current_step: string, checklist: string, payment: string}
     * }
     */
    public function filterData(Request $request, FestivalEdition $edition): array
    {
        $categories = $edition->categories()->with('direction')->orderBy('name')->get();
        $currentSteps = $this->currentStepOptions($edition);
        $requestedCategory = $request->integer('category');
        $requestedCurrentStep = $request->integer('current_step');
        $statuses = collect(FestivalEntryStatus::cases())->pluck('value')->all();
        $requestedStatus = (string) $request->query('status', '');
        $requestedQueue = (string) $request->query('queue', '');
        $requestedChecklist = (string) $request->query('checklist', '');
        $requestedPayment = (string) $request->query('payment', '');

        return [
            'categories' => $categories,
            'current_steps' => $currentSteps,
            'filters' => [
                'q' => $request->string('q')->trim()->toString(),
                'status' => in_array($requestedStatus, $statuses, true) ? $requestedStatus : '',
                'category' => $requestedCategory > 0 && $categories->contains('id', $requestedCategory) ? (string) $requestedCategory : '',
                'queue' => in_array($requestedQueue, $this->queueKeys(), true) ? $requestedQueue : '',
                'current_step' => $requestedCurrentStep > 0 && $currentSteps->contains('id', $requestedCurrentStep) ? (string) $requestedCurrentStep : '',
                'checklist' => in_array($requestedChecklist, [self::ChecklistOpen, self::ChecklistComplete], true) ? $requestedChecklist : '',
                'payment' => in_array($requestedPayment, [self::PaymentIncomplete, self::PaymentPaid, self::PaymentNotRequired], true) ? $requestedPayment : '',
            ],
        ];
    }

    /**
     * @param  array{q: string, status: string, category: string, queue: string, current_step: string, checklist: string, payment: string}  $filters
     */
    public function query(FestivalEdition $edition, array $filters, bool $includeApplicant): EloquentBuilder
    {
        $entriesTable = (new FestivalEntry)->getTable();

        return FestivalEntry::query()
            ->joinSub(
                $this->filteredProgressQuery($edition, $filters, $includeApplicant),
                'festival_application_index',
                fn (JoinClause $join): JoinClause => $join->on('festival_application_index.entry_id', '=', $entriesTable.'.id'),
            )
            ->where($entriesTable.'.account_id', $edition->account_id)
            ->where($entriesTable.'.festival_edition_id', $edition->id)
            ->select([
                $entriesTable.'.*',
                'festival_application_index.current_step_id',
                'festival_application_index.current_step_status',
                'festival_application_index.current_workflow_step_id',
                'festival_application_index.step_count as workflow_steps_count',
                'festival_application_index.current_checklist_open_count',
                'festival_application_index.current_payment_state',
                'festival_application_index.queue_key as application_queue',
            ]);
    }

    /**
     * @param  array{q: string, status: string, category: string, queue: string, current_step: string, checklist: string, payment: string}  $filters
     * @return Collection<string, int>
     */
    public function queueCounts(FestivalEdition $edition, array $filters, bool $includeApplicant): Collection
    {
        $facetedFilters = array_replace($filters, ['queue' => '']);
        $counts = DB::query()
            ->fromSub($this->filteredProgressQuery($edition, $facetedFilters, $includeApplicant), 'festival_application_queue_counts')
            ->select('queue_key')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('queue_key')
            ->pluck('aggregate', 'queue_key');

        $queueCounts = collect(['all' => $counts->sum()]);
        foreach ($this->queueKeys() as $queue) {
            $queueCounts->put($queue, (int) ($counts[$queue] ?? 0));
        }

        return $queueCounts->map(fn (mixed $count): int => (int) $count);
    }

    /** @return list<string> */
    public function queueKeys(): array
    {
        return [
            self::QueueAwaitingReview,
            self::QueueCorrectionsRequested,
            self::QueuePaymentIncomplete,
            self::QueueNotSubmitted,
            self::QueueComplete,
            self::QueueClosed,
        ];
    }

    /** @return Collection<int, FestivalWorkflowStep> */
    private function currentStepOptions(FestivalEdition $edition): Collection
    {
        $entryStepsTable = (new FestivalEntryStep)->getTable();
        $entriesTable = (new FestivalEntry)->getTable();
        $workflowStepsTable = (new FestivalWorkflowStep)->getTable();

        $referencedStepIds = FestivalEntryStep::query()
            ->join($entriesTable.' as current_step_option_entries', 'current_step_option_entries.id', '=', $entryStepsTable.'.festival_entry_id')
            ->where('current_step_option_entries.account_id', $edition->account_id)
            ->where('current_step_option_entries.festival_edition_id', $edition->id)
            ->where($entryStepsTable.'.account_id', $edition->account_id)
            ->select($entryStepsTable.'.festival_workflow_step_id');

        return FestivalWorkflowStep::query()
            ->where($workflowStepsTable.'.account_id', $edition->account_id)
            ->whereHas('workflow', fn (EloquentBuilder $query) => $query
                ->where('account_id', $edition->account_id)
                ->where('festival_edition_id', $edition->id))
            ->where(function (EloquentBuilder $query) use ($referencedStepIds, $workflowStepsTable): void {
                $query->where(function (EloquentBuilder $query) use ($workflowStepsTable): void {
                    $query->where($workflowStepsTable.'.is_active', true)
                        ->whereHas('workflow', fn (EloquentBuilder $query) => $query->where('is_active', true));
                })->orWhereIn($workflowStepsTable.'.id', $referencedStepIds);
            })
            ->with('workflow')
            ->get()
            ->sortBy(fn (FestivalWorkflowStep $step): string => sprintf(
                '%010d:%020d:%010d:%020d',
                $step->workflow->sort_order,
                $step->workflow->id,
                $step->sort_order,
                $step->id,
            ))
            ->values();
    }

    /**
     * @param  array{q: string, status: string, category: string, queue: string, current_step: string, checklist: string, payment: string}  $filters
     */
    private function filteredProgressQuery(FestivalEdition $edition, array $filters, bool $includeApplicant): QueryBuilder
    {
        $query = DB::query()->fromSub(
            $this->progressQuery($edition, $filters, $includeApplicant),
            'festival_application_progress',
        );

        if ($filters['current_step'] !== '') {
            $query->where('current_workflow_step_id', (int) $filters['current_step']);
        }

        if ($filters['checklist'] !== '') {
            $query->whereNotNull('current_step_id');
            $filters['checklist'] === self::ChecklistOpen
                ? $query->where('current_checklist_open_count', '>', 0)
                : $query->where('current_checklist_open_count', 0);
        }

        if ($filters['payment'] !== '') {
            $query->whereNotNull('current_step_id')->where('current_payment_state', $filters['payment']);
        }

        if ($filters['queue'] !== '') {
            $query->where('queue_key', $filters['queue']);
        }

        return $query;
    }

    /**
     * @param  array{q: string, status: string, category: string, queue: string, current_step: string, checklist: string, payment: string}  $filters
     */
    private function progressQuery(FestivalEdition $edition, array $filters, bool $includeApplicant): QueryBuilder
    {
        $entriesTable = (new FestivalEntry)->getTable();
        $entryAlias = 'application_entries';
        $terminalStatuses = [FestivalEntryStatus::Rejected->value, FestivalEntryStatus::Withdrawn->value];

        $query = DB::table($entriesTable.' as '.$entryAlias)
            ->leftJoinSub(
                $this->currentStepsQuery($edition),
                'application_current_steps',
                fn (JoinClause $join): JoinClause => $join->on('application_current_steps.festival_entry_id', '=', $entryAlias.'.id'),
            )
            ->leftJoinSub(
                $this->stepTotalsQuery($edition),
                'application_step_totals',
                fn (JoinClause $join): JoinClause => $join->on('application_step_totals.festival_entry_id', '=', $entryAlias.'.id'),
            )
            ->leftJoinSub(
                $this->currentStepChargesQuery($edition),
                'application_current_charges',
                fn (JoinClause $join): JoinClause => $join->on('application_current_charges.festival_entry_step_id', '=', 'application_current_steps.current_step_id'),
            )
            ->leftJoinSub(
                $this->currentStepChecklistQuery($edition),
                'application_current_checklist',
                fn (JoinClause $join): JoinClause => $join->on('application_current_checklist.festival_entry_step_id', '=', 'application_current_steps.current_step_id'),
            )
            ->where($entryAlias.'.account_id', $edition->account_id)
            ->where($entryAlias.'.festival_edition_id', $edition->id)
            ->select([
                $entryAlias.'.id as entry_id',
                $entryAlias.'.status as entry_status',
                $entryAlias.'.festival_category_id',
                'application_current_steps.current_step_id',
                'application_current_steps.current_step_status',
                'application_current_steps.current_workflow_step_id',
            ])
            ->selectRaw('COALESCE(application_step_totals.step_count, 0) as step_count')
            ->selectRaw('COALESCE(application_current_checklist.open_count, 0) as current_checklist_open_count')
            ->selectRaw(
                'CASE
                    WHEN application_current_steps.current_step_id IS NULL THEN NULL
                    WHEN COALESCE(application_current_charges.incomplete_count, 0) > 0 THEN ?
                    WHEN COALESCE(application_current_charges.positive_non_cancelled_count, 0) > 0 THEN ?
                    ELSE ?
                END as current_payment_state',
                [self::PaymentIncomplete, self::PaymentPaid, self::PaymentNotRequired],
            )
            ->selectRaw(
                'CASE
                    WHEN '.$entryAlias.'.status = ? THEN ?
                    WHEN '.$entryAlias.'.status NOT IN (?, ?) AND application_current_steps.current_step_status = ? THEN ?
                    WHEN '.$entryAlias.'.status NOT IN (?, ?) AND application_current_steps.current_step_status = ? THEN ?
                    WHEN '.$entryAlias.'.status NOT IN (?, ?) AND application_current_steps.current_step_status = ? AND COALESCE(application_current_charges.incomplete_count, 0) > 0 THEN ?
                    WHEN '.$entryAlias.'.status NOT IN (?, ?) AND NOT ('.$entryAlias.'.status = ? AND COALESCE(application_step_totals.step_count, 0) > 0 AND application_current_steps.current_step_id IS NULL) THEN ?
                    WHEN '.$entryAlias.'.status = ? AND COALESCE(application_step_totals.step_count, 0) > 0 AND application_current_steps.current_step_id IS NULL THEN ?
                    ELSE ?
                END as queue_key',
                [
                    FestivalEntryStatus::ChangesPending->value,
                    self::QueueCorrectionsRequested,
                    ...$terminalStatuses,
                    FestivalEntryStepStatus::Submitted->value,
                    self::QueueAwaitingReview,
                    ...$terminalStatuses,
                    FestivalEntryStepStatus::ChangesRequested->value,
                    self::QueueCorrectionsRequested,
                    ...$terminalStatuses,
                    FestivalEntryStepStatus::Draft->value,
                    self::QueuePaymentIncomplete,
                    ...$terminalStatuses,
                    FestivalEntryStatus::Accepted->value,
                    self::QueueNotSubmitted,
                    FestivalEntryStatus::Accepted->value,
                    self::QueueComplete,
                    self::QueueClosed,
                ],
            );

        $this->applyEntryFilters($query, $edition, $filters, $includeApplicant, $entryAlias);

        return $query;
    }

    /**
     * @param  array{q: string, status: string, category: string, queue: string, current_step: string, checklist: string, payment: string}  $filters
     */
    private function applyEntryFilters(QueryBuilder $query, FestivalEdition $edition, array $filters, bool $includeApplicant, string $entryAlias): void
    {
        $portalUsersTable = (new FestivalPortalUser)->getTable();
        $searchTerms = preg_split('/\s+/u', $filters['q'], -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($searchTerms as $term) {
            $search = '%'.$term.'%';
            $query->where(function (QueryBuilder $query) use ($edition, $entryAlias, $includeApplicant, $portalUsersTable, $search): void {
                $query->where($entryAlias.'.entry_name', 'like', $search)
                    ->orWhere($entryAlias.'.act_title', 'like', $search);

                if ($includeApplicant) {
                    $query->orWhereExists(fn (QueryBuilder $portalUsers) => $portalUsers
                        ->selectRaw('1')
                        ->from($portalUsersTable.' as application_search_portal_users')
                        ->whereColumn('application_search_portal_users.id', $entryAlias.'.festival_portal_user_id')
                        ->where('application_search_portal_users.account_id', $edition->account_id)
                        ->where(function (QueryBuilder $portalUsers) use ($search): void {
                            $portalUsers->where('first_name', 'like', $search)
                                ->orWhere('last_name', 'like', $search)
                                ->orWhere('email', 'like', $search);
                        }));
                }
            });
        }

        if ($filters['status'] !== '') {
            $query->where($entryAlias.'.status', $filters['status']);
        }

        if ($filters['category'] !== '') {
            $query->where($entryAlias.'.festival_category_id', (int) $filters['category']);
        }
    }

    private function currentStepsQuery(FestivalEdition $edition): QueryBuilder
    {
        $entryStepsTable = (new FestivalEntryStep)->getTable();
        $entriesTable = (new FestivalEntry)->getTable();
        $workflowStepsTable = (new FestivalWorkflowStep)->getTable();
        $workflowsTable = (new FestivalWorkflow)->getTable();

        $rankedSteps = DB::table($entryStepsTable.' as ranked_entry_steps')
            ->join($entriesTable.' as ranked_entries', 'ranked_entries.id', '=', 'ranked_entry_steps.festival_entry_id')
            ->join($workflowStepsTable.' as ranked_workflow_steps', 'ranked_workflow_steps.id', '=', 'ranked_entry_steps.festival_workflow_step_id')
            ->join($workflowsTable.' as ranked_workflows', 'ranked_workflows.id', '=', 'ranked_workflow_steps.festival_workflow_id')
            ->where('ranked_entries.account_id', $edition->account_id)
            ->where('ranked_entries.festival_edition_id', $edition->id)
            ->where('ranked_entry_steps.account_id', $edition->account_id)
            ->where('ranked_workflow_steps.account_id', $edition->account_id)
            ->where('ranked_workflows.account_id', $edition->account_id)
            ->where('ranked_workflows.festival_edition_id', $edition->id)
            ->where('ranked_entry_steps.status', '!=', FestivalEntryStepStatus::Approved->value)
            ->select([
                'ranked_entry_steps.festival_entry_id',
                'ranked_entry_steps.id as current_step_id',
                'ranked_entry_steps.status as current_step_status',
                'ranked_entry_steps.festival_workflow_step_id as current_workflow_step_id',
            ])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY ranked_entry_steps.festival_entry_id ORDER BY ranked_workflow_steps.sort_order, ranked_entry_steps.id) as current_step_position');

        return DB::query()
            ->fromSub($rankedSteps, 'ranked_application_steps')
            ->where('current_step_position', 1)
            ->select([
                'festival_entry_id',
                'current_step_id',
                'current_step_status',
                'current_workflow_step_id',
            ]);
    }

    private function stepTotalsQuery(FestivalEdition $edition): QueryBuilder
    {
        $entryStepsTable = (new FestivalEntryStep)->getTable();
        $entriesTable = (new FestivalEntry)->getTable();

        return DB::table($entryStepsTable.' as total_application_steps')
            ->join($entriesTable.' as total_application_entries', 'total_application_entries.id', '=', 'total_application_steps.festival_entry_id')
            ->where('total_application_entries.account_id', $edition->account_id)
            ->where('total_application_entries.festival_edition_id', $edition->id)
            ->where('total_application_steps.account_id', $edition->account_id)
            ->select('total_application_steps.festival_entry_id')
            ->selectRaw('COUNT(*) as step_count')
            ->groupBy('total_application_steps.festival_entry_id');
    }

    private function currentStepChargesQuery(FestivalEdition $edition): QueryBuilder
    {
        $chargesTable = (new FestivalCharge)->getTable();
        $entryStepsTable = (new FestivalEntryStep)->getTable();
        $entriesTable = (new FestivalEntry)->getTable();

        return DB::table($chargesTable.' as indexed_current_charges')
            ->join($entryStepsTable.' as indexed_charge_steps', 'indexed_charge_steps.id', '=', 'indexed_current_charges.festival_entry_step_id')
            ->join($entriesTable.' as indexed_charge_entries', 'indexed_charge_entries.id', '=', 'indexed_charge_steps.festival_entry_id')
            ->where('indexed_current_charges.account_id', $edition->account_id)
            ->where('indexed_charge_steps.account_id', $edition->account_id)
            ->where('indexed_charge_entries.account_id', $edition->account_id)
            ->where('indexed_charge_entries.festival_edition_id', $edition->id)
            ->select('indexed_current_charges.festival_entry_step_id')
            ->selectRaw(
                'SUM(CASE WHEN indexed_current_charges.amount_cents > 0 AND indexed_current_charges.status != ? THEN 1 ELSE 0 END) as positive_non_cancelled_count',
                [FestivalChargeStatus::Cancelled->value],
            )
            ->selectRaw(
                'SUM(CASE WHEN indexed_current_charges.amount_cents > 0 AND indexed_current_charges.status NOT IN (?, ?) THEN 1 ELSE 0 END) as incomplete_count',
                [FestivalChargeStatus::Paid->value, FestivalChargeStatus::Cancelled->value],
            )
            ->groupBy('indexed_current_charges.festival_entry_step_id');
    }

    private function currentStepChecklistQuery(FestivalEdition $edition): QueryBuilder
    {
        $requirementsTable = (new FestivalEntryRequirement)->getTable();
        $definitionsTable = (new FestivalRequirementDefinition)->getTable();
        $submissionsTable = (new FestivalSubmission)->getTable();
        $entryStepsTable = (new FestivalEntryStep)->getTable();
        $entriesTable = (new FestivalEntry)->getTable();
        $agreement = FestivalRequirementInputType::Agreement->value;
        $file = FestivalRequirementInputType::File->value;

        return DB::table($requirementsTable.' as indexed_current_requirements')
            ->join($entryStepsTable.' as indexed_requirement_steps', 'indexed_requirement_steps.id', '=', 'indexed_current_requirements.festival_entry_step_id')
            ->join($entriesTable.' as indexed_requirement_entries', 'indexed_requirement_entries.id', '=', 'indexed_requirement_steps.festival_entry_id')
            ->join($definitionsTable.' as indexed_requirement_definitions', 'indexed_requirement_definitions.id', '=', 'indexed_current_requirements.festival_requirement_definition_id')
            ->leftJoin($submissionsTable.' as indexed_requirement_submissions', 'indexed_requirement_submissions.festival_entry_requirement_id', '=', 'indexed_current_requirements.id')
            ->where('indexed_current_requirements.account_id', $edition->account_id)
            ->where('indexed_requirement_steps.account_id', $edition->account_id)
            ->where('indexed_requirement_entries.account_id', $edition->account_id)
            ->where('indexed_requirement_entries.festival_edition_id', $edition->id)
            ->where('indexed_requirement_definitions.account_id', $edition->account_id)
            ->select('indexed_current_requirements.festival_entry_step_id')
            ->selectRaw(
                "SUM(CASE
                    WHEN (indexed_requirement_definitions.is_required = 1 OR indexed_requirement_definitions.input_type = ?)
                        AND NOT (
                            (indexed_requirement_definitions.input_type != ? AND indexed_current_requirements.status = ?)
                            OR (
                                indexed_current_requirements.status IN (?, ?)
                                AND indexed_requirement_submissions.id IS NOT NULL
                                AND (
                                    (
                                        indexed_requirement_definitions.input_type = ?
                                        AND TRIM(COALESCE(indexed_requirement_submissions.disk, '')) != ''
                                        AND TRIM(COALESCE(indexed_requirement_submissions.path, '')) != ''
                                    )
                                    OR (
                                        indexed_requirement_definitions.input_type = ?
                                        AND (
                                            (JSON_TYPE(JSON_EXTRACT(indexed_requirement_submissions.value_json, '$.value')) = 'BOOLEAN' AND JSON_UNQUOTE(JSON_EXTRACT(indexed_requirement_submissions.value_json, '$.value')) = 'true')
                                            OR (JSON_TYPE(JSON_EXTRACT(indexed_requirement_submissions.value_json, '$.value')) = 'INTEGER' AND JSON_UNQUOTE(JSON_EXTRACT(indexed_requirement_submissions.value_json, '$.value')) = '1')
                                            OR (JSON_TYPE(JSON_EXTRACT(indexed_requirement_submissions.value_json, '$.value')) = 'STRING' AND JSON_UNQUOTE(JSON_EXTRACT(indexed_requirement_submissions.value_json, '$.value')) = '1')
                                        )
                                    )
                                    OR (
                                        indexed_requirement_definitions.input_type NOT IN (?, ?)
                                        AND JSON_CONTAINS_PATH(indexed_requirement_submissions.value_json, 'one', '$.value') = 1
                                    )
                                )
                            )
                        )
                    THEN 1 ELSE 0
                END) as open_count",
                [
                    $agreement,
                    $agreement,
                    FestivalRequirementStatus::Waived->value,
                    FestivalRequirementStatus::Submitted->value,
                    FestivalRequirementStatus::Accepted->value,
                    $file,
                    $agreement,
                    $file,
                    $agreement,
                ],
            )
            ->groupBy('indexed_current_requirements.festival_entry_step_id');
    }
}

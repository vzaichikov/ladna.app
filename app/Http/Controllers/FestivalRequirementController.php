<?php

namespace App\Http\Controllers;

use App\Enums\FestivalFieldScope;
use App\Enums\FestivalRequirementInputType;
use App\Http\Requests\FestivalMoveRequest;
use App\Http\Requests\FestivalRequirementRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalWorkflowStep;
use App\Support\Festivals\FestivalRequirementDeadlineResolver;
use App\Support\Festivals\FestivalSettingsOrder;
use App\Support\Festivals\FestivalWorkspaceAccess;
use App\Support\Payments\PaymentAmounts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FestivalRequirementController extends Controller
{
    public function __construct(
        private readonly FestivalWorkspaceAccess $workspaceAccess,
        private readonly FestivalSettingsOrder $settingsOrder,
        private readonly FestivalRequirementDeadlineResolver $deadlineResolver,
    ) {}

    public function index(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $filters = [
            'q' => $request->string('q')->trim()->toString(),
            'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : '',
            'category' => $request->integer('category'),
            'workflow_step' => $request->integer('workflow_step'),
            'input_type' => collect(FestivalRequirementInputType::cases())->pluck('value')->contains($request->query('input_type')) ? $request->query('input_type') : '',
            'scope' => collect(FestivalFieldScope::cases())->pluck('value')->contains($request->query('scope')) ? $request->query('scope') : '',
        ];
        $requirements = FestivalRequirementDefinition::query()
            ->where('festival_edition_id', $festivalEdition->id)
            ->with(['category', 'workflowStep.workflow'])
            ->withExists('entryRequirements')
            ->when($filters['q'] !== '', fn ($query) => $query->where('name', 'like', '%'.$filters['q'].'%'))
            ->when($filters['status'] !== '', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->when($filters['category'] > 0, fn ($query) => $query->where('festival_category_id', $filters['category']))
            ->when($filters['workflow_step'] > 0, fn ($query) => $query->where('festival_workflow_step_id', $filters['workflow_step']))
            ->when($filters['input_type'] !== '', fn ($query) => $query->where('input_type', $filters['input_type']))
            ->when($filters['scope'] !== '', fn ($query) => $query->where('subject_scope', $filters['scope']))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();
        $postConfirmationEditingByRequirement = $requirements->getCollection()
            ->mapWithKeys(function (FestivalRequirementDefinition $requirement) use ($festivalEdition): array {
                $requirement->setRelation('edition', $festivalEdition);
                $allowed = $this->deadlineResolver->allowsPostConfirmationEdits($requirement);

                return [$requirement->id => [
                    'allowed' => $allowed,
                    'editable_until' => $allowed ? $this->deadlineResolver->editableUntil($requirement) : null,
                ]];
            });

        return view('festivals.staff.settings.requirements', [
            'account' => $account,
            'edition' => $festivalEdition,
            'requirements' => $requirements,
            'categories' => $festivalEdition->categories()->get(['id', 'name']),
            'workflows' => $festivalEdition->workflows()->with('steps:id,festival_workflow_id,title')->get(['id', 'name']),
            'postConfirmationEditingByRequirement' => $postConfirmationEditingByRequirement,
            'filters' => $filters,
            'hasFilters' => $filters['q'] !== ''
                || $filters['status'] !== ''
                || $filters['category'] > 0
                || $filters['workflow_step'] > 0
                || $filters['input_type'] !== ''
                || $filters['scope'] !== '',
            'canReorder' => $filters['q'] === ''
                && $filters['status'] === ''
                && $filters['category'] === 0
                && $filters['input_type'] === ''
                && $filters['scope'] === '',
            'workspacePermissions' => $permissions,
        ]);
    }

    public function create(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);

        return $this->formView($account, $festivalEdition, new FestivalRequirementDefinition([
            'is_required' => true,
            'is_active' => true,
        ]), $permissions);
    }

    public function store(FestivalRequirementRequest $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        $data = $this->requirementData($festivalEdition, $request->validated());
        FestivalRequirementDefinition::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $festivalEdition->id,
            ...$data,
            'is_required' => $data['is_required'] ?? true,
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $this->settingsOrder->next(FestivalRequirementDefinition::query()->where('festival_edition_id', $festivalEdition->id)),
        ]);

        return $this->redirect($account, $festivalEdition);
    }

    public function edit(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalRequirementDefinition $festivalRequirementDefinition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertRequirement($account, $festivalEdition, $festivalRequirementDefinition);

        return $this->formView($account, $festivalEdition, $festivalRequirementDefinition, $permissions);
    }

    public function update(FestivalRequirementRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalRequirementDefinition $festivalRequirementDefinition): RedirectResponse
    {
        $this->assertRequirement($account, $festivalEdition, $festivalRequirementDefinition);
        $data = $this->requirementData($festivalEdition, $request->validated(), $festivalRequirementDefinition);
        $festivalRequirementDefinition->update([
            ...$data,
            'is_required' => $data['is_required'] ?? false,
            'is_active' => $data['is_active'] ?? false,
        ]);

        return $this->redirect($account, $festivalEdition);
    }

    public function toggle(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalRequirementDefinition $festivalRequirementDefinition): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertRequirement($account, $festivalEdition, $festivalRequirementDefinition);
        $festivalRequirementDefinition->update(['is_active' => ! $festivalRequirementDefinition->is_active]);

        return back()->with('status', __('app.festival_status_saved'));
    }

    public function move(FestivalMoveRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalRequirementDefinition $festivalRequirementDefinition): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertRequirement($account, $festivalEdition, $festivalRequirementDefinition);
        $query = FestivalRequirementDefinition::query()->where('festival_edition_id', $festivalEdition->id);

        if ($request->validated('ordering_scope') === 'workflow_step') {
            $workflowStepId = $festivalRequirementDefinition->festival_workflow_step_id;
            abort_unless($workflowStepId !== null, 422);
            $this->settingsOrder->moveWithin(
                $festivalRequirementDefinition,
                $query,
                $request->validated('direction'),
                fn (Model $requirement): bool => (int) $requirement->festival_workflow_step_id === (int) $workflowStepId,
            );
        } else {
            $this->settingsOrder->move(
                $festivalRequirementDefinition,
                $query,
                $request->validated('direction'),
            );
        }

        return back()->with('status', __('app.festival_order_saved'));
    }

    public function destroy(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalRequirementDefinition $festivalRequirementDefinition): RedirectResponse
    {
        $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertRequirement($account, $festivalEdition, $festivalRequirementDefinition);

        try {
            $deleted = DB::transaction(function () use ($account, $festivalEdition, $festivalRequirementDefinition): bool {
                $requirement = FestivalRequirementDefinition::query()
                    ->whereKey($festivalRequirementDefinition->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertRequirement($account, $festivalEdition, $requirement);

                if ($requirement->entryRequirements()->exists()) {
                    return false;
                }

                return (bool) $requirement->delete();
            }, 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '23000') {
                throw $exception;
            }

            $deleted = false;
        }

        if (! $deleted) {
            return redirect()->route('dashboard.accounts.festivals.settings.requirements', [$account, $festivalEdition])
                ->withErrors(['festival_requirement' => __('app.festival_registration_field_delete_linked')]);
        }

        return redirect()->route('dashboard.accounts.festivals.settings.requirements', [$account, $festivalEdition])
            ->with('status', __('app.festival_registration_field_deleted'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function requirementData(FestivalEdition $edition, array $data, ?FestivalRequirementDefinition $requirement = null): array
    {
        $this->assertDependencies($edition, $data);
        $options = collect($data['options'] ?? [])->map(function (array $option): array {
            return [
                'value' => $option['value'],
                'label' => $option['label'],
            ];
        })->all();
        $optionPrices = collect($data['options'] ?? [])
            ->filter(fn (array $option): bool => filled($option['price'] ?? null))
            ->mapWithKeys(fn (array $option): array => [
                $option['value'] => (int) PaymentAmounts::decimalToCents($option['price']),
            ])
            ->all();
        $amountCents = (int) PaymentAmounts::decimalToCents($data['price_amount'] ?? 0);
        $pricing = match ($data['pricing_mode']) {
            'flat_when_true' => ['mode' => 'flat_when_true', 'amount_cents' => $amountCents],
            'per_unit' => ['mode' => 'per_unit', 'unit_amount_cents' => $amountCents],
            'option_prices' => ['mode' => 'option_prices', 'prices' => $optionPrices],
            default => ['mode' => 'none'],
        };
        if ($data['input_type'] === FestivalRequirementInputType::Agreement->value) {
            $data['is_required'] = true;
        }
        $dueRule = $this->deadlineRule($data, 'due');
        $allowPostConfirmationEdits = (bool) ($data['allow_post_confirmation_edits'] ?? false);
        $editableUntilRule = $allowPostConfirmationEdits
            ? $this->deadlineRule($data, 'editable_until')
            : null;
        $validation = array_replace($requirement?->validation ?? [], [
            'allowed_hosts' => $data['allowed_hosts'] ?? [],
            'due_rule' => $dueRule,
            'allow_post_confirmation_edits' => $allowPostConfirmationEdits,
            'editable_until_rule' => $editableUntilRule,
        ]);
        unset(
            $data['pricing_mode'],
            $data['price_amount'],
            $data['allowed_hosts'],
            $data['sort_order'],
            $data['due_reference'],
            $data['due_offset_days'],
            $data['allow_post_confirmation_edits'],
            $data['editable_until_reference'],
            $data['editable_until_offset_days'],
        );

        if ($dueRule !== null || data_get($requirement?->validation, 'due_rule') !== null) {
            $data['due_at'] = null;
        }

        return [...$data, 'options' => $options, 'pricing' => $pricing, 'validation' => $validation];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{reference: string, offset_days: int}|null
     */
    private function deadlineRule(array $data, string $prefix): ?array
    {
        $reference = $data[$prefix.'_reference'] ?? null;
        $offsetDays = $data[$prefix.'_offset_days'] ?? null;

        if (! is_string($reference) || ! in_array($reference, FestivalRequirementDeadlineResolver::References, true) || $offsetDays === null) {
            return null;
        }

        return ['reference' => $reference, 'offset_days' => (int) $offsetDays];
    }

    /** @param array<string, mixed> $data */
    private function assertDependencies(FestivalEdition $edition, array $data): void
    {
        if (isset($data['festival_category_id'])) {
            abort_unless($edition->categories()->whereKey($data['festival_category_id'])->exists(), 422);
        }

        abort_unless(FestivalWorkflowStep::query()
            ->whereKey($data['festival_workflow_step_id'])
            ->whereHas('workflow', fn ($query) => $query->where('festival_edition_id', $edition->id))
            ->exists(), 422);
    }

    /**
     * @param  array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool}  $permissions
     */
    private function formView(Account $account, FestivalEdition $edition, FestivalRequirementDefinition $requirement, array $permissions): View
    {
        $edition->load(['categories', 'workflows.steps']);

        return view('festivals.staff.settings.requirement-form', [
            'account' => $account,
            'edition' => $edition,
            'requirement' => $requirement,
            'deadlineReferences' => FestivalRequirementDeadlineResolver::References,
            'resolvedDueAt' => $this->deadlineResolver->dueAt($requirement),
            'resolvedEditableUntil' => $this->deadlineResolver->allowsPostConfirmationEdits($requirement)
                ? $this->deadlineResolver->editableUntil($requirement)
                : null,
            'workspacePermissions' => $permissions,
        ]);
    }

    /** @return array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool} */
    private function managerPermissions(Request $request, Account $account, FestivalEdition $edition): array
    {
        $this->assertEdition($account, $edition);
        $permissions = $this->workspaceAccess->permissions($request->user(), $account, $edition);
        abort_unless($permissions['manage'], 403);

        return $permissions;
    }

    private function authorizeManager(Request $request, Account $account): void
    {
        abort_unless($request->user()?->can('manageFestivals', $account), 403);
    }

    private function assertEdition(Account $account, FestivalEdition $edition): void
    {
        abort_unless($edition->account_id === $account->id, 404);
    }

    private function assertRequirement(Account $account, FestivalEdition $edition, FestivalRequirementDefinition $requirement): void
    {
        $this->assertEdition($account, $edition);
        abort_unless($requirement->account_id === $account->id && $requirement->festival_edition_id === $edition->id, 404);
    }

    private function redirect(Account $account, FestivalEdition $edition): RedirectResponse
    {
        return redirect()->route('dashboard.accounts.festivals.settings.requirements', [$account, $edition])
            ->with('status', __('app.festival_requirement_saved'));
    }
}

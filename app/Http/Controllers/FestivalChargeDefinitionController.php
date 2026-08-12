<?php

namespace App\Http\Controllers;

use App\Http\Requests\FestivalChargeDefinitionRequest;
use App\Http\Requests\FestivalMoveRequest;
use App\Models\Account;
use App\Models\FestivalChargeDefinition;
use App\Models\FestivalEdition;
use App\Models\FestivalWorkflowStep;
use App\Support\Festivals\FestivalSettingsOrder;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FestivalChargeDefinitionController extends Controller
{
    public function __construct(
        private readonly FestivalWorkspaceAccess $workspaceAccess,
        private readonly FestivalSettingsOrder $settingsOrder,
    ) {}

    public function index(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->financePermissions($request, $account, $festivalEdition);
        $kinds = ['qualification', 'participation', 'late', 'custom'];
        $filters = [
            'q' => $request->string('q')->trim()->toString(),
            'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : '',
            'category' => $request->integer('category'),
            'workflow_step' => $request->integer('workflow_step'),
            'kind' => in_array($request->query('kind'), $kinds, true) ? $request->query('kind') : '',
        ];
        $fees = FestivalChargeDefinition::query()
            ->where('festival_edition_id', $festivalEdition->id)
            ->with(['category', 'workflowStep.workflow'])
            ->when($filters['q'] !== '', fn ($query) => $query->where('name', 'like', '%'.$filters['q'].'%'))
            ->when($filters['status'] !== '', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->when($filters['category'] > 0, fn ($query) => $query->where('festival_category_id', $filters['category']))
            ->when($filters['workflow_step'] > 0, fn ($query) => $query->where('festival_workflow_step_id', $filters['workflow_step']))
            ->when($filters['kind'] !== '', fn ($query) => $query->where('kind', $filters['kind']))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return view('festivals.staff.settings.fees', [
            'account' => $account,
            'edition' => $festivalEdition,
            'fees' => $fees,
            'categories' => $festivalEdition->categories()->get(['id', 'name']),
            'workflows' => $festivalEdition->workflows()->with('steps:id,festival_workflow_id,title')->get(['id', 'name']),
            'kinds' => $kinds,
            'filters' => $filters,
            'hasFilters' => $filters['q'] !== ''
                || $filters['status'] !== ''
                || $filters['category'] > 0
                || $filters['workflow_step'] > 0
                || $filters['kind'] !== '',
            'workspacePermissions' => $permissions,
        ]);
    }

    public function create(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->financePermissions($request, $account, $festivalEdition);

        return $this->formView($account, $festivalEdition, new FestivalChargeDefinition(['is_active' => true]), $permissions);
    }

    public function store(FestivalChargeDefinitionRequest $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        $data = $this->feeData($festivalEdition, $request->validated());
        FestivalChargeDefinition::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $festivalEdition->id,
            ...$data,
            'currency' => $festivalEdition->currency,
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $this->settingsOrder->next(FestivalChargeDefinition::query()->where('festival_edition_id', $festivalEdition->id)),
        ]);

        return $this->redirect($account, $festivalEdition);
    }

    public function edit(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalChargeDefinition $festivalChargeDefinition): View
    {
        $permissions = $this->financePermissions($request, $account, $festivalEdition);
        $this->assertFee($account, $festivalEdition, $festivalChargeDefinition);

        return $this->formView($account, $festivalEdition, $festivalChargeDefinition, $permissions);
    }

    public function update(FestivalChargeDefinitionRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalChargeDefinition $festivalChargeDefinition): RedirectResponse
    {
        $this->assertFee($account, $festivalEdition, $festivalChargeDefinition);
        $data = $this->feeData($festivalEdition, $request->validated());
        $festivalChargeDefinition->update([
            ...$data,
            'currency' => $festivalEdition->currency,
            'is_active' => $data['is_active'] ?? false,
        ]);

        return $this->redirect($account, $festivalEdition);
    }

    public function toggle(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalChargeDefinition $festivalChargeDefinition): RedirectResponse
    {
        $this->authorizeFinance($request, $account);
        $this->assertFee($account, $festivalEdition, $festivalChargeDefinition);
        $festivalChargeDefinition->update(['is_active' => ! $festivalChargeDefinition->is_active]);

        return back()->with('status', __('app.festival_status_saved'));
    }

    public function move(FestivalMoveRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalChargeDefinition $festivalChargeDefinition): RedirectResponse
    {
        $this->authorizeFinance($request, $account);
        $this->assertFee($account, $festivalEdition, $festivalChargeDefinition);
        $this->settingsOrder->move(
            $festivalChargeDefinition,
            FestivalChargeDefinition::query()->where('festival_edition_id', $festivalEdition->id),
            $request->validated('direction'),
        );

        return back()->with('status', __('app.festival_order_saved'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function feeData(FestivalEdition $edition, array $data): array
    {
        if (isset($data['festival_category_id'])) {
            abort_unless($edition->categories()->whereKey($data['festival_category_id'])->exists(), 422);
        }

        abort_unless(FestivalWorkflowStep::query()
            ->whereKey($data['festival_workflow_step_id'])
            ->whereHas('workflow', fn ($query) => $query->where('festival_edition_id', $edition->id))
            ->exists(), 422);
        if ($data['pricing_mode'] === 'fixed') {
            $data['included_members'] = null;
            $data['additional_member_amount_cents'] = null;
        }
        if ($data['due_policy'] === 'fixed') {
            $data['due_days_after_approval'] = null;
            $data['due_hard_cap_at'] = null;
        } else {
            $data['due_at'] = null;
        }
        unset($data['sort_order']);

        return $data;
    }

    /**
     * @param  array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool}  $permissions
     */
    private function formView(Account $account, FestivalEdition $edition, FestivalChargeDefinition $fee, array $permissions): View
    {
        $edition->load(['categories', 'workflows.steps']);

        return view('festivals.staff.settings.fee-form', [
            'account' => $account,
            'edition' => $edition,
            'fee' => $fee,
            'workspacePermissions' => $permissions,
        ]);
    }

    /** @return array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool} */
    private function financePermissions(Request $request, Account $account, FestivalEdition $edition): array
    {
        $this->assertEdition($account, $edition);
        $permissions = $this->workspaceAccess->permissions($request->user(), $account, $edition);
        abort_unless($permissions['finance'], 403);

        return $permissions;
    }

    private function authorizeFinance(Request $request, Account $account): void
    {
        abort_unless($request->user()?->can('manageFestivalFinance', $account), 403);
    }

    private function assertEdition(Account $account, FestivalEdition $edition): void
    {
        abort_unless($edition->account_id === $account->id, 404);
    }

    private function assertFee(Account $account, FestivalEdition $edition, FestivalChargeDefinition $fee): void
    {
        $this->assertEdition($account, $edition);
        abort_unless($fee->account_id === $account->id && $fee->festival_edition_id === $edition->id, 404);
    }

    private function redirect(Account $account, FestivalEdition $edition): RedirectResponse
    {
        return redirect()->route('dashboard.accounts.festivals.settings.fees', [$account, $edition])
            ->with('status', __('app.festival_charge_saved'));
    }
}

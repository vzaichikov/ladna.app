<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\ProvisionFestivalWorkflow;
use App\Http\Requests\FestivalMoveRequest;
use App\Http\Requests\FestivalWorkflowRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalWorkflow;
use App\Support\Festivals\FestivalSettingsOrder;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FestivalWorkflowController extends Controller
{
    public function __construct(
        private readonly FestivalWorkspaceAccess $workspaceAccess,
        private readonly FestivalSettingsOrder $settingsOrder,
    ) {}

    public function index(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $filters = [
            'q' => $request->string('q')->trim()->toString(),
            'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : '',
        ];
        $workflows = $festivalEdition->workflows()
            ->withCount(['categories', 'steps'])
            ->when($filters['q'] !== '', fn ($query) => $query->where('name', 'like', '%'.$filters['q'].'%'))
            ->when($filters['status'] !== '', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->paginate(20)
            ->withQueryString();

        return view('festivals.staff.settings.workflows', [
            'account' => $account,
            'edition' => $festivalEdition,
            'workflows' => $workflows,
            'filters' => $filters,
            'hasFilters' => $filters['q'] !== '' || $filters['status'] !== '',
            'workspacePermissions' => $permissions,
        ]);
    }

    public function create(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);

        return $this->formView($account, $festivalEdition, new FestivalWorkflow(['is_active' => true]), $permissions);
    }

    public function store(FestivalWorkflowRequest $request, Account $account, FestivalEdition $festivalEdition, ProvisionFestivalWorkflow $provision): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        $data = $request->validated();
        $provision->execute(
            $festivalEdition,
            $data['name'],
            $provision->standardSteps($data['application_review_mode'], $data['technical_review_mode']),
        );

        return $this->redirect($account, $festivalEdition);
    }

    public function edit(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertWorkflow($account, $festivalEdition, $festivalWorkflow);

        return $this->formView($account, $festivalEdition, $festivalWorkflow, $permissions);
    }

    public function update(FestivalWorkflowRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow): RedirectResponse
    {
        $this->assertWorkflow($account, $festivalEdition, $festivalWorkflow);
        $data = $request->safe()->only(['name', 'is_active']);
        $data['is_active'] = $data['is_active'] ?? false;

        if (! $data['is_active'] && $festivalWorkflow->is_active && $festivalWorkflow->categories()->exists()) {
            throw ValidationException::withMessages(['workflow' => __('app.festival_workflow_dependency_block')]);
        }

        $festivalWorkflow->update($data);

        return $this->redirect($account, $festivalEdition);
    }

    public function toggle(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertWorkflow($account, $festivalEdition, $festivalWorkflow);

        if ($festivalWorkflow->is_active && $festivalWorkflow->categories()->exists()) {
            throw ValidationException::withMessages(['workflow' => __('app.festival_workflow_dependency_block')]);
        }

        $festivalWorkflow->update(['is_active' => ! $festivalWorkflow->is_active]);

        return back()->with('status', __('app.festival_status_saved'));
    }

    public function move(FestivalMoveRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertWorkflow($account, $festivalEdition, $festivalWorkflow);
        $this->settingsOrder->move($festivalWorkflow, $festivalEdition->workflows(), $request->validated('direction'));

        return back()->with('status', __('app.festival_order_saved'));
    }

    /**
     * @param  array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool}  $permissions
     */
    private function formView(Account $account, FestivalEdition $edition, FestivalWorkflow $workflow, array $permissions): View
    {
        return view('festivals.staff.settings.workflow-form', [
            'account' => $account,
            'edition' => $edition,
            'workflow' => $workflow,
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

    private function assertWorkflow(Account $account, FestivalEdition $edition, FestivalWorkflow $workflow): void
    {
        $this->assertEdition($account, $edition);
        abort_unless($workflow->account_id === $account->id && $workflow->festival_edition_id === $edition->id, 404);
    }

    private function redirect(Account $account, FestivalEdition $edition): RedirectResponse
    {
        return redirect()->route('dashboard.accounts.festivals.settings.workflows', [$account, $edition])
            ->with('status', __('app.festival_workflow_saved'));
    }
}

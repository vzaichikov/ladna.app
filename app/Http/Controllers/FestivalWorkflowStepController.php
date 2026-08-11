<?php

namespace App\Http\Controllers;

use App\Enums\FestivalWorkflowReviewMode;
use App\Enums\FestivalWorkflowStepType;
use App\Http\Requests\FestivalMoveRequest;
use App\Http\Requests\FestivalWorkflowStepRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalEntryStep;
use App\Models\FestivalWorkflow;
use App\Models\FestivalWorkflowStep;
use App\Support\Festivals\FestivalSettingsOrder;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FestivalWorkflowStepController extends Controller
{
    public function __construct(
        private readonly FestivalWorkspaceAccess $workspaceAccess,
        private readonly FestivalSettingsOrder $settingsOrder,
    ) {}

    public function index(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertWorkflow($account, $festivalEdition, $festivalWorkflow);
        $filters = [
            'q' => $request->string('q')->trim()->toString(),
            'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : '',
            'type' => collect(FestivalWorkflowStepType::cases())->pluck('value')->contains($request->query('type')) ? $request->query('type') : '',
            'review_mode' => collect(FestivalWorkflowReviewMode::cases())->pluck('value')->contains($request->query('review_mode')) ? $request->query('review_mode') : '',
        ];
        $steps = $festivalWorkflow->steps()
            ->withCount(['requirementDefinitions', 'chargeDefinitions'])
            ->when($filters['q'] !== '', fn ($query) => $query->where('title', 'like', '%'.$filters['q'].'%'))
            ->when($filters['status'] !== '', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->when($filters['type'] !== '', fn ($query) => $query->where('type', $filters['type']))
            ->when($filters['review_mode'] !== '', fn ($query) => $query->where('review_mode', $filters['review_mode']))
            ->paginate(20)
            ->withQueryString();

        return view('festivals.staff.settings.workflow-steps', [
            'account' => $account,
            'edition' => $festivalEdition,
            'workflow' => $festivalWorkflow,
            'steps' => $steps,
            'filters' => $filters,
            'hasFilters' => collect($filters)->contains(fn ($value) => filled($value)),
            'workspacePermissions' => $permissions,
        ]);
    }

    public function create(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertWorkflow($account, $festivalEdition, $festivalWorkflow);

        return $this->formView($account, $festivalEdition, $festivalWorkflow, new FestivalWorkflowStep([
            'sort_order' => $this->settingsOrder->next($festivalWorkflow->steps()),
            'is_active' => true,
        ]), $permissions);
    }

    public function store(FestivalWorkflowStepRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow): RedirectResponse
    {
        $this->assertWorkflow($account, $festivalEdition, $festivalWorkflow);
        $data = $request->validated();
        $festivalWorkflow->steps()->create([
            'account_id' => $account->id,
            ...$data,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $this->redirect($account, $festivalEdition, $festivalWorkflow);
    }

    public function edit(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow, FestivalWorkflowStep $festivalWorkflowStep): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertStep($account, $festivalEdition, $festivalWorkflow, $festivalWorkflowStep);

        return $this->formView($account, $festivalEdition, $festivalWorkflow, $festivalWorkflowStep, $permissions);
    }

    public function update(FestivalWorkflowStepRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow, FestivalWorkflowStep $festivalWorkflowStep): RedirectResponse
    {
        $this->assertStep($account, $festivalEdition, $festivalWorkflow, $festivalWorkflowStep);
        $data = $request->validated();
        $festivalWorkflowStep->update([...$data, 'is_active' => $data['is_active'] ?? false]);

        return $this->redirect($account, $festivalEdition, $festivalWorkflow);
    }

    public function toggle(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow, FestivalWorkflowStep $festivalWorkflowStep): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertStep($account, $festivalEdition, $festivalWorkflow, $festivalWorkflowStep);
        $referenced = $festivalWorkflowStep->requirementDefinitions()->exists()
            || $festivalWorkflowStep->chargeDefinitions()->exists()
            || FestivalEntryStep::query()->where('festival_workflow_step_id', $festivalWorkflowStep->id)->exists();

        if ($festivalWorkflowStep->is_active && $referenced) {
            throw ValidationException::withMessages(['step' => __('app.festival_workflow_step_dependency_block')]);
        }

        $festivalWorkflowStep->update(['is_active' => ! $festivalWorkflowStep->is_active]);

        return back()->with('status', __('app.festival_status_saved'));
    }

    public function move(FestivalMoveRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow, FestivalWorkflowStep $festivalWorkflowStep): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertStep($account, $festivalEdition, $festivalWorkflow, $festivalWorkflowStep);
        $this->settingsOrder->move($festivalWorkflowStep, $festivalWorkflow->steps(), $request->validated('direction'));

        return back()->with('status', __('app.festival_order_saved'));
    }

    /**
     * @param  array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool}  $permissions
     */
    private function formView(Account $account, FestivalEdition $edition, FestivalWorkflow $workflow, FestivalWorkflowStep $step, array $permissions): View
    {
        return view('festivals.staff.settings.workflow-step-form', [
            'account' => $account,
            'edition' => $edition,
            'workflow' => $workflow,
            'step' => $step,
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

    private function assertStep(Account $account, FestivalEdition $edition, FestivalWorkflow $workflow, FestivalWorkflowStep $step): void
    {
        $this->assertWorkflow($account, $edition, $workflow);
        abort_unless($step->account_id === $account->id && $step->festival_workflow_id === $workflow->id, 404);
    }

    private function redirect(Account $account, FestivalEdition $edition, FestivalWorkflow $workflow): RedirectResponse
    {
        return redirect()->route('dashboard.accounts.festivals.workflow-steps.index', [$account, $edition, $workflow])
            ->with('status', __('app.festival_workflow_step_saved'));
    }
}

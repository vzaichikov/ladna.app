<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\ManageFestivalWorkflowStep;
use App\Actions\Festivals\UpdateFestivalWorkflowStepCompletionNotifications;
use App\Enums\FestivalWorkflowReviewMode;
use App\Enums\FestivalWorkflowStepType;
use App\Http\Requests\FestivalMoveRequest;
use App\Http\Requests\FestivalWorkflowStepCompletionNotificationRequest;
use App\Http\Requests\FestivalWorkflowStepRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalWorkflow;
use App\Models\FestivalWorkflowStep;
use App\Support\Festivals\FestivalSettingsOrder;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ->withCount(['entrySteps', 'requirementDefinitions', 'chargeDefinitions'])
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

    public function store(FestivalWorkflowStepRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow, ManageFestivalWorkflowStep $manager): RedirectResponse
    {
        $this->assertWorkflow($account, $festivalEdition, $festivalWorkflow);
        $manager->create($festivalWorkflow, $request->validated());

        return $this->redirect($account, $festivalEdition, $festivalWorkflow);
    }

    public function edit(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow, FestivalWorkflowStep $festivalWorkflowStep): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertStep($account, $festivalEdition, $festivalWorkflow, $festivalWorkflowStep);
        $activeTab = $request->query('tab') === 'completion-notifications' ? 'completion-notifications' : 'details';

        return $this->formView($account, $festivalEdition, $festivalWorkflow, $festivalWorkflowStep, $permissions, $activeTab);
    }

    public function update(FestivalWorkflowStepRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow, FestivalWorkflowStep $festivalWorkflowStep, ManageFestivalWorkflowStep $manager): RedirectResponse
    {
        $this->assertStep($account, $festivalEdition, $festivalWorkflow, $festivalWorkflowStep);
        $manager->update($festivalWorkflowStep, $request->validated());

        return $this->redirect($account, $festivalEdition, $festivalWorkflow);
    }

    public function updateCompletionNotifications(FestivalWorkflowStepCompletionNotificationRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow, FestivalWorkflowStep $festivalWorkflowStep, UpdateFestivalWorkflowStepCompletionNotifications $updateNotifications): RedirectResponse
    {
        $this->assertStep($account, $festivalEdition, $festivalWorkflow, $festivalWorkflowStep);
        $validated = $request->validated();
        $updateNotifications->execute($festivalWorkflowStep, $validated['completion_notifications']);

        return redirect()->route('dashboard.accounts.festivals.workflow-steps.edit', [
            $account,
            $festivalEdition,
            $festivalWorkflow,
            $festivalWorkflowStep,
            'tab' => 'completion-notifications',
        ])->with('status', __('app.festival_workflow_step_completion_notifications_saved'));
    }

    public function toggle(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow, FestivalWorkflowStep $festivalWorkflowStep, ManageFestivalWorkflowStep $manager): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertStep($account, $festivalEdition, $festivalWorkflow, $festivalWorkflowStep);
        $manager->toggle($festivalWorkflowStep);

        return back()->with('status', __('app.festival_status_saved'));
    }

    public function move(FestivalMoveRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow, FestivalWorkflowStep $festivalWorkflowStep, ManageFestivalWorkflowStep $manager): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertStep($account, $festivalEdition, $festivalWorkflow, $festivalWorkflowStep);
        $manager->move($festivalWorkflowStep, $request->validated('direction'));

        return back()->with('status', __('app.festival_order_saved'));
    }

    public function destroy(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkflow $festivalWorkflow, FestivalWorkflowStep $festivalWorkflowStep, ManageFestivalWorkflowStep $manager): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertStep($account, $festivalEdition, $festivalWorkflow, $festivalWorkflowStep);

        try {
            $manager->delete($festivalWorkflowStep);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '23000') {
                throw $exception;
            }

            return back()->withErrors(['festival_workflow_step' => __('app.festival_workflow_step_dependency_block')]);
        }

        return back()->with('status', __('app.festival_workflow_step_deleted'));
    }

    /**
     * @param  array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool}  $permissions
     */
    private function formView(Account $account, FestivalEdition $edition, FestivalWorkflow $workflow, FestivalWorkflowStep $step, array $permissions, string $activeTab = 'details'): View
    {
        return view('festivals.staff.settings.workflow-step-form', [
            'account' => $account,
            'edition' => $edition,
            'workflow' => $workflow,
            'step' => $step,
            'hasSummaryStep' => $workflow->steps()->where('type', FestivalWorkflowStepType::Summary->value)->exists(),
            'activeTab' => $activeTab,
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
        return redirect()->route('dashboard.accounts.festivals.workflows.edit', [$account, $edition, $workflow])
            ->with('status', __('app.festival_workflow_step_saved'));
    }
}

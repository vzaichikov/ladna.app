<?php

namespace App\Http\Controllers;

use App\Http\Requests\FestivalMoveRequest;
use App\Http\Requests\FestivalStageRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalStage;
use App\Support\Festivals\FestivalSettingsOrder;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FestivalStageController extends Controller
{
    public function __construct(
        private readonly FestivalWorkspaceAccess $workspaceAccess,
        private readonly FestivalSettingsOrder $settingsOrder,
    ) {}

    public function index(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->schedulePermissions($request, $account, $festivalEdition);
        $filters = [
            'q' => $request->string('q')->trim()->toString(),
            'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : '',
        ];
        $stages = $festivalEdition->stages()
            ->withCount('slots')
            ->when($filters['q'] !== '', fn ($query) => $query->where('name', 'like', '%'.$filters['q'].'%'))
            ->when($filters['status'] !== '', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->paginate(20)
            ->withQueryString();

        return view('festivals.staff.settings.stages', [
            'account' => $account,
            'edition' => $festivalEdition,
            'stages' => $stages,
            'filters' => $filters,
            'hasFilters' => $filters['q'] !== '' || $filters['status'] !== '',
            'workspacePermissions' => $permissions,
        ]);
    }

    public function create(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->schedulePermissions($request, $account, $festivalEdition);

        return view('festivals.staff.settings.stage-form', [
            'account' => $account,
            'edition' => $festivalEdition,
            'stage' => new FestivalStage(['is_active' => true]),
            'workspacePermissions' => $permissions,
        ]);
    }

    public function store(FestivalStageRequest $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        $data = $request->validated();
        $festivalEdition->stages()->create([
            'account_id' => $account->id,
            ...$data,
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $this->settingsOrder->next($festivalEdition->stages()),
        ]);

        return $this->redirect($account, $festivalEdition);
    }

    public function edit(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalStage $festivalStage): View
    {
        $permissions = $this->schedulePermissions($request, $account, $festivalEdition);
        $this->assertStage($account, $festivalEdition, $festivalStage);

        return view('festivals.staff.settings.stage-form', [
            'account' => $account,
            'edition' => $festivalEdition,
            'stage' => $festivalStage,
            'workspacePermissions' => $permissions,
        ]);
    }

    public function update(FestivalStageRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalStage $festivalStage): RedirectResponse
    {
        $this->assertStage($account, $festivalEdition, $festivalStage);
        $data = $request->validated();
        $festivalStage->update([...$data, 'is_active' => $data['is_active'] ?? $festivalStage->is_active]);

        return $this->redirect($account, $festivalEdition);
    }

    public function toggle(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalStage $festivalStage): RedirectResponse
    {
        $this->authorizeSchedule($request, $account);
        $this->assertStage($account, $festivalEdition, $festivalStage);
        $festivalStage->update(['is_active' => ! $festivalStage->is_active]);

        return back()->with('status', __('app.festival_scene_status_saved'));
    }

    public function move(FestivalMoveRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalStage $festivalStage): RedirectResponse
    {
        $this->authorizeSchedule($request, $account);
        $this->assertStage($account, $festivalEdition, $festivalStage);
        $this->settingsOrder->move($festivalStage, $festivalEdition->stages(), $request->validated('direction'));

        return back()->with('status', __('app.festival_order_saved'));
    }

    /** @return array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool} */
    private function schedulePermissions(Request $request, Account $account, FestivalEdition $edition): array
    {
        $this->assertEdition($account, $edition);
        $permissions = $this->workspaceAccess->permissions($request->user(), $account, $edition);
        abort_unless($permissions['schedule'], 403);

        return $permissions;
    }

    private function authorizeSchedule(Request $request, Account $account): void
    {
        abort_unless($request->user()?->can('manageFestivalSchedule', $account), 403);
    }

    private function assertEdition(Account $account, FestivalEdition $edition): void
    {
        abort_unless($edition->account_id === $account->id, 404);
    }

    private function assertStage(Account $account, FestivalEdition $edition, FestivalStage $stage): void
    {
        $this->assertEdition($account, $edition);
        abort_unless($stage->account_id === $account->id && $stage->festival_edition_id === $edition->id, 404);
    }

    private function redirect(Account $account, FestivalEdition $edition): RedirectResponse
    {
        return redirect()->route('dashboard.accounts.festivals.settings.stages', [$account, $edition])
            ->with('status', __('app.festival_scene_saved'));
    }
}

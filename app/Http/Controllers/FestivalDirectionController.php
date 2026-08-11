<?php

namespace App\Http\Controllers;

use App\Http\Requests\FestivalDirectionRequest;
use App\Http\Requests\FestivalMoveRequest;
use App\Models\Account;
use App\Models\FestivalDirection;
use App\Models\FestivalEdition;
use App\Support\Festivals\FestivalSettingsOrder;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FestivalDirectionController extends Controller
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

        $directions = $festivalEdition->directions()
            ->withCount('categories')
            ->when($filters['q'] !== '', fn ($query) => $query->where('name', 'like', '%'.$filters['q'].'%'))
            ->when($filters['status'] !== '', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->paginate(20)
            ->withQueryString();

        return view('festivals.staff.settings.directions', [
            'account' => $account,
            'edition' => $festivalEdition,
            'directions' => $directions,
            'filters' => $filters,
            'hasFilters' => $filters['q'] !== '' || $filters['status'] !== '',
            'workspacePermissions' => $permissions,
        ]);
    }

    public function create(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);

        return view('festivals.staff.settings.direction-form', [
            'account' => $account,
            'edition' => $festivalEdition,
            'direction' => new FestivalDirection(['is_active' => true]),
            'workspacePermissions' => $permissions,
        ]);
    }

    public function store(FestivalDirectionRequest $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        $data = $request->validated();
        $festivalEdition->directions()->create([
            'account_id' => $account->id,
            ...$data,
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $this->settingsOrder->next($festivalEdition->directions()),
        ]);

        return $this->redirect($account, $festivalEdition);
    }

    public function edit(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalDirection $festivalDirection): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertDirection($account, $festivalEdition, $festivalDirection);

        return view('festivals.staff.settings.direction-form', [
            'account' => $account,
            'edition' => $festivalEdition,
            'direction' => $festivalDirection,
            'workspacePermissions' => $permissions,
        ]);
    }

    public function update(FestivalDirectionRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalDirection $festivalDirection): RedirectResponse
    {
        $this->assertDirection($account, $festivalEdition, $festivalDirection);
        $data = $request->validated();
        DB::transaction(function () use ($account, $festivalEdition, $festivalDirection, $data): void {
            $direction = FestivalDirection::query()->whereKey($festivalDirection->id)->lockForUpdate()->firstOrFail();
            $this->assertDirection($account, $festivalEdition, $direction);
            $isActive = $data['is_active'] ?? $direction->is_active;
            $this->assertCanDeactivate($direction, $isActive);
            $direction->update([...$data, 'is_active' => $isActive]);
        }, 3);

        return $this->redirect($account, $festivalEdition);
    }

    public function toggle(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalDirection $festivalDirection): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertDirection($account, $festivalEdition, $festivalDirection);
        DB::transaction(function () use ($account, $festivalEdition, $festivalDirection): void {
            $direction = FestivalDirection::query()->whereKey($festivalDirection->id)->lockForUpdate()->firstOrFail();
            $this->assertDirection($account, $festivalEdition, $direction);
            $isActive = ! $direction->is_active;
            $this->assertCanDeactivate($direction, $isActive);
            $direction->update(['is_active' => $isActive]);
        }, 3);

        return back()->with('status', __('app.festival_status_saved'));
    }

    public function move(FestivalMoveRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalDirection $festivalDirection): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertDirection($account, $festivalEdition, $festivalDirection);
        $this->settingsOrder->move($festivalDirection, $festivalEdition->directions(), $request->validated('direction'));

        return back()->with('status', __('app.festival_order_saved'));
    }

    private function assertCanDeactivate(FestivalDirection $direction, bool $isActive): void
    {
        if (! $isActive && $direction->categories()->exists()) {
            throw ValidationException::withMessages(['direction' => __('app.festival_direction_dependency_block')]);
        }
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

    private function assertDirection(Account $account, FestivalEdition $edition, FestivalDirection $direction): void
    {
        $this->assertEdition($account, $edition);
        abort_unless($direction->account_id === $account->id && $direction->festival_edition_id === $edition->id, 404);
    }

    private function redirect(Account $account, FestivalEdition $edition): RedirectResponse
    {
        return redirect()->route('dashboard.accounts.festivals.settings.directions', [$account, $edition])
            ->with('status', __('app.festival_direction_saved'));
    }
}

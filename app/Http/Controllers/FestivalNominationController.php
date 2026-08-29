<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\FestivalActivityRecorder;
use App\Http\Requests\FestivalMoveRequest;
use App\Http\Requests\FestivalNominationRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalNomination;
use App\Support\Festivals\FestivalSettingsOrder;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FestivalNominationController extends Controller
{
    public function __construct(
        private readonly FestivalWorkspaceAccess $workspaceAccess,
        private readonly FestivalSettingsOrder $settingsOrder,
        private readonly FestivalActivityRecorder $activity,
    ) {}

    public function index(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $filters = [
            'q' => $request->string('q')->trim()->toString(),
            'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : '',
            'visibility' => in_array($request->query('visibility'), ['shown', 'hidden'], true) ? $request->query('visibility') : '',
        ];
        $nominations = $festivalEdition->nominations()
            ->withCount('participants')
            ->when($filters['q'] !== '', fn ($query) => $query->where('name', 'like', '%'.$filters['q'].'%'))
            ->when($filters['status'] !== '', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->when($filters['visibility'] !== '', fn ($query) => $query->where('show_in_mini_app', $filters['visibility'] === 'shown'))
            ->paginate(20)
            ->withQueryString();

        return view('festivals.staff.settings.nominations', [
            'account' => $account,
            'edition' => $festivalEdition,
            'nominations' => $nominations,
            'filters' => $filters,
            'hasFilters' => collect($filters)->contains(fn (string $value): bool => $value !== ''),
            'workspacePermissions' => $permissions,
        ]);
    }

    public function create(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);

        return view('festivals.staff.settings.nomination-form', [
            'account' => $account,
            'edition' => $festivalEdition,
            'nomination' => new FestivalNomination(['is_active' => true, 'show_in_mini_app' => false]),
            'workspacePermissions' => $permissions,
        ]);
    }

    public function store(FestivalNominationRequest $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        $data = $request->validated();
        $nomination = $festivalEdition->nominations()->create([
            'account_id' => $account->id,
            ...$data,
            'is_active' => $data['is_active'] ?? true,
            'show_in_mini_app' => $data['show_in_mini_app'] ?? false,
            'sort_order' => $this->settingsOrder->next($festivalEdition->nominations()),
        ]);
        $this->activity->record($nomination, 'nomination.created', $festivalEdition, $request->user());

        return $this->redirect($account, $festivalEdition);
    }

    public function edit(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalNomination $festivalNomination): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertNomination($account, $festivalEdition, $festivalNomination);

        return view('festivals.staff.settings.nomination-form', [
            'account' => $account,
            'edition' => $festivalEdition,
            'nomination' => $festivalNomination,
            'workspacePermissions' => $permissions,
        ]);
    }

    public function update(FestivalNominationRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalNomination $festivalNomination): RedirectResponse
    {
        $this->assertNomination($account, $festivalEdition, $festivalNomination);
        $festivalNomination->update($request->validated());
        $this->activity->record($festivalNomination, 'nomination.updated', $festivalEdition, $request->user());

        return $this->redirect($account, $festivalEdition);
    }

    public function toggle(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalNomination $festivalNomination): RedirectResponse
    {
        $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertNomination($account, $festivalEdition, $festivalNomination);
        $field = $request->string('field')->toString() ?: 'is_active';
        abort_unless(in_array($field, ['is_active', 'show_in_mini_app'], true), 404);
        $festivalNomination->update([$field => ! $festivalNomination->{$field}]);
        $this->activity->record($festivalNomination, 'nomination.updated', $festivalEdition, $request->user(), [$field => $festivalNomination->{$field}]);

        return back()->with('status', __('app.festival_nomination_saved'));
    }

    public function move(FestivalMoveRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalNomination $festivalNomination): RedirectResponse
    {
        $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertNomination($account, $festivalEdition, $festivalNomination);
        $this->settingsOrder->move($festivalNomination, $festivalEdition->nominations(), $request->validated('direction'));

        return back()->with('status', __('app.festival_order_saved'));
    }

    public function destroy(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalNomination $festivalNomination): RedirectResponse
    {
        $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertNomination($account, $festivalEdition, $festivalNomination);
        DB::transaction(function () use ($request, $festivalEdition, $festivalNomination): void {
            $nomination = FestivalNomination::query()->whereKey($festivalNomination->id)->lockForUpdate()->firstOrFail();
            if ($nomination->participants()->exists()) {
                throw ValidationException::withMessages(['nomination' => __('app.festival_nomination_delete_assigned')]);
            }
            $this->activity->record($nomination, 'nomination.deleted', $festivalEdition, $request->user());
            $nomination->delete();
        }, 3);

        return back()->with('status', __('app.festival_nomination_deleted'));
    }

    /** @return array<string, bool> */
    private function managerPermissions(Request $request, Account $account, FestivalEdition $edition): array
    {
        $this->assertEdition($account, $edition);
        $permissions = $this->workspaceAccess->permissions($request->user(), $account, $edition);
        abort_unless($permissions['manage'], 403);

        return $permissions;
    }

    private function assertEdition(Account $account, FestivalEdition $edition): void
    {
        abort_unless($edition->account_id === $account->id, 404);
    }

    private function assertNomination(Account $account, FestivalEdition $edition, FestivalNomination $nomination): void
    {
        $this->assertEdition($account, $edition);
        abort_unless($nomination->account_id === $account->id && $nomination->festival_edition_id === $edition->id, 404);
    }

    private function redirect(Account $account, FestivalEdition $edition): RedirectResponse
    {
        return redirect()->route('dashboard.accounts.festivals.settings.nominations', [$account, $edition])
            ->with('status', __('app.festival_nomination_saved'));
    }
}

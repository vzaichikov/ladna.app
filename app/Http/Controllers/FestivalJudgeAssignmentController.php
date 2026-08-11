<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFestivalJudgeAssignmentRequest;
use App\Http\Requests\UpdateFestivalJudgeAssignmentRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalPortalUser;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FestivalJudgeAssignmentController extends Controller
{
    public function __construct(private readonly FestivalWorkspaceAccess $workspaceAccess) {}

    public function index(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $categories = $festivalEdition->categories()->get();
        $categoryId = $request->integer('category_id');
        $filters = [
            'q' => $request->string('q')->trim()->toString(),
            'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : '',
            'category_id' => $categories->contains('id', $categoryId) ? $categoryId : null,
        ];

        $assignments = $festivalEdition->judgeAssignments()
            ->with(['user', 'portalUser', 'categories'])
            ->withCount('scoreSheets')
            ->when($filters['q'] !== '', fn ($query) => $query->where('display_name', 'like', '%'.$filters['q'].'%'))
            ->when($filters['status'] !== '', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->when($filters['category_id'], fn ($query, int $id) => $query->whereHas('categories', fn ($categoryQuery) => $categoryQuery->whereKey($id)))
            ->orderBy('display_name')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return view('festivals.staff.judging.judges', [
            'account' => $account,
            'edition' => $festivalEdition,
            'assignments' => $assignments,
            'categories' => $categories,
            'filters' => $filters,
            'hasFilters' => $filters['q'] !== '' || $filters['status'] !== '' || $filters['category_id'] !== null,
            'workspacePermissions' => $permissions,
        ]);
    }

    public function create(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);

        return $this->formView($account, $festivalEdition, new FestivalJudgeAssignment(['is_active' => true]), $permissions);
    }

    public function store(StoreFestivalJudgeAssignmentRequest $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        $data = $request->validated();

        DB::transaction(function () use ($account, $festivalEdition, $data): void {
            FestivalEdition::query()->whereKey($festivalEdition->id)->lockForUpdate()->firstOrFail();
            $identityColumn = isset($data['user_id']) ? 'user_id' : 'festival_portal_user_id';

            if (FestivalJudgeAssignment::query()->where('festival_edition_id', $festivalEdition->id)->where($identityColumn, $data[$identityColumn])->exists()) {
                throw ValidationException::withMessages([$identityColumn => __('app.festival_judge_identity_duplicate')]);
            }

            $assignment = $festivalEdition->judgeAssignments()->create([
                'account_id' => $account->id,
                'user_id' => $data['user_id'] ?? null,
                'festival_portal_user_id' => $data['festival_portal_user_id'] ?? null,
                'display_name' => $data['display_name'],
                'is_head_judge' => $data['is_head_judge'] ?? false,
                'is_active' => $data['is_active'] ?? true,
            ]);
            $this->syncCategories($assignment, $account, $data['category_ids']);
        }, 3);

        return $this->redirect($account, $festivalEdition);
    }

    public function edit(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalJudgeAssignment $festivalJudgeAssignment): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertAssignment($account, $festivalEdition, $festivalJudgeAssignment);
        $festivalJudgeAssignment->load(['user', 'portalUser', 'categories']);

        return $this->formView($account, $festivalEdition, $festivalJudgeAssignment, $permissions);
    }

    public function update(UpdateFestivalJudgeAssignmentRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalJudgeAssignment $festivalJudgeAssignment): RedirectResponse
    {
        $this->assertAssignment($account, $festivalEdition, $festivalJudgeAssignment);
        $data = $request->validated();

        DB::transaction(function () use ($account, $festivalEdition, $festivalJudgeAssignment, $data): void {
            $assignment = FestivalJudgeAssignment::query()->whereKey($festivalJudgeAssignment->id)->lockForUpdate()->firstOrFail();
            $this->assertAssignment($account, $festivalEdition, $assignment);
            $assignment->update([
                'display_name' => $data['display_name'],
                'is_head_judge' => $data['is_head_judge'] ?? false,
                'is_active' => $data['is_active'] ?? false,
            ]);
            $this->syncCategories($assignment, $account, $data['category_ids']);
        }, 3);

        return $this->redirect($account, $festivalEdition);
    }

    public function toggle(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalJudgeAssignment $festivalJudgeAssignment): RedirectResponse
    {
        $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertAssignment($account, $festivalEdition, $festivalJudgeAssignment);

        DB::transaction(function () use ($account, $festivalEdition, $festivalJudgeAssignment): void {
            $assignment = FestivalJudgeAssignment::query()->whereKey($festivalJudgeAssignment->id)->lockForUpdate()->firstOrFail();
            $this->assertAssignment($account, $festivalEdition, $assignment);
            $assignment->update(['is_active' => ! $assignment->is_active]);
        }, 3);

        return back()->with('status', __('app.festival_status_saved'));
    }

    /**
     * @param  array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool}  $permissions
     */
    private function formView(Account $account, FestivalEdition $edition, FestivalJudgeAssignment $assignment, array $permissions): View
    {
        return view('festivals.staff.judging.judge-form', [
            'account' => $account,
            'edition' => $edition,
            'assignment' => $assignment,
            'categories' => $edition->categories()->get(),
            'staffUsers' => $account->users()->orderBy('name')->orderBy('email')->get(['users.id', 'users.name', 'users.email']),
            'portalUsers' => FestivalPortalUser::query()->whereBelongsTo($account)->orderBy('first_name')->orderBy('last_name')->orderBy('email')->get(),
            'workspacePermissions' => $permissions,
        ]);
    }

    /** @param array<int, int|string> $categoryIds */
    private function syncCategories(FestivalJudgeAssignment $assignment, Account $account, array $categoryIds): void
    {
        $assignment->categories()->sync(collect($categoryIds)->mapWithKeys(fn (int|string $categoryId): array => [(int) $categoryId => ['account_id' => $account->id]])->all());
    }

    /** @return array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool} */
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

    private function assertAssignment(Account $account, FestivalEdition $edition, FestivalJudgeAssignment $assignment): void
    {
        $this->assertEdition($account, $edition);
        abort_unless($assignment->account_id === $account->id && $assignment->festival_edition_id === $edition->id, 404);
    }

    private function redirect(Account $account, FestivalEdition $edition): RedirectResponse
    {
        return redirect()->route('dashboard.accounts.festivals.judging.judges.index', [$account, $edition])
            ->with('status', __('app.festival_judge_saved'));
    }
}

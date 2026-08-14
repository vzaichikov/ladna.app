<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\SaveFestivalCategory;
use App\Http\Requests\FestivalCategoryRequest;
use App\Http\Requests\FestivalMoveRequest;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FestivalCategoryController extends Controller
{
    public function __construct(private readonly FestivalWorkspaceAccess $workspaceAccess) {}

    public function index(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $filters = [
            'q' => $request->string('q')->trim()->toString(),
            'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : '',
            'direction' => $request->integer('direction'),
            'workflow' => $request->integer('workflow'),
        ];
        $categories = $festivalEdition->categories()
            ->with(['direction', 'registrationWorkflow'])
            ->withCount(['entries', 'acceptedEntries', 'capacityOccupyingEntries'])
            ->when($filters['q'] !== '', fn ($query) => $query->where('name', 'like', '%'.$filters['q'].'%'))
            ->when($filters['status'] !== '', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->when($filters['direction'] > 0, fn ($query) => $query->where('festival_direction_id', $filters['direction']))
            ->when($filters['workflow'] > 0, fn ($query) => $query->where('festival_workflow_id', $filters['workflow']))
            ->paginate(20)
            ->withQueryString();

        return view('festivals.staff.settings.categories', [
            'account' => $account,
            'edition' => $festivalEdition,
            'categories' => $categories,
            'directions' => $festivalEdition->directions()->get(['id', 'name']),
            'workflows' => $festivalEdition->workflows()->get(['id', 'name']),
            'filters' => $filters,
            'hasFilters' => $filters['q'] !== '' || $filters['status'] !== '' || $filters['direction'] > 0 || $filters['workflow'] > 0,
            'workspacePermissions' => $permissions,
        ]);
    }

    public function create(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);

        return $this->formView($account, $festivalEdition, new FestivalCategory, $permissions);
    }

    public function store(FestivalCategoryRequest $request, Account $account, FestivalEdition $festivalEdition, SaveFestivalCategory $save): RedirectResponse
    {
        $save->execute($account, $festivalEdition, $request->validated());

        return $this->redirect($account, $festivalEdition);
    }

    public function edit(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalCategory $festivalCategory): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertCategory($account, $festivalEdition, $festivalCategory);

        return $this->formView($account, $festivalEdition, $festivalCategory, $permissions);
    }

    public function update(FestivalCategoryRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalCategory $festivalCategory, SaveFestivalCategory $save): RedirectResponse
    {
        $this->assertCategory($account, $festivalEdition, $festivalCategory);
        $save->execute($account, $festivalEdition, $request->validated(), $festivalCategory);

        return $this->redirect($account, $festivalEdition);
    }

    public function toggle(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalCategory $festivalCategory): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertCategory($account, $festivalEdition, $festivalCategory);
        $festivalCategory->update(['is_active' => ! $festivalCategory->is_active]);

        return back()->with('status', __('app.festival_status_saved'));
    }

    public function move(FestivalMoveRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalCategory $festivalCategory): RedirectResponse
    {
        $this->authorizeManager($request, $account);
        $this->assertCategory($account, $festivalEdition, $festivalCategory);
        $this->moveWithin($festivalCategory, $festivalEdition->categories()->get(), $request->validated('direction'));

        return back()->with('status', __('app.festival_order_saved'));
    }

    /**
     * @param  array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool}  $permissions
     */
    private function formView(Account $account, FestivalEdition $edition, FestivalCategory $category, array $permissions): View
    {
        $directions = $edition->directions()
            ->where(function ($query) use ($category): void {
                $query->where('is_active', true);

                if ($category->exists) {
                    $query->orWhere('id', $category->festival_direction_id);
                }
            })
            ->get();
        $workflows = $edition->workflows()
            ->where(function ($query) use ($category): void {
                $query->where('is_active', true);

                if ($category->exists && $category->festival_workflow_id) {
                    $query->orWhere('id', $category->festival_workflow_id);
                }
            })
            ->get();

        return view('festivals.staff.settings.category-form', [
            'account' => $account,
            'edition' => $edition,
            'category' => $category,
            'directions' => $directions,
            'workflows' => $workflows,
            'workspacePermissions' => $permissions,
        ]);
    }

    /** @param Collection<int, Model> $categories */
    private function moveWithin(Model $category, Collection $categories, string $move): void
    {
        DB::transaction(function () use ($category, $categories, $move): void {
            $categories = $categories->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values();
            foreach ($categories as $index => $item) {
                $item->forceFill(['sort_order' => ($index + 1) * 10])->save();
            }

            $index = $categories->search(fn (Model $item): bool => $item->is($category));
            $targetIndex = $move === 'up' ? $index - 1 : $index + 1;

            if ($index === false || ! $categories->has($targetIndex)) {
                return;
            }

            $target = $categories[$targetIndex];
            $currentOrder = $categories[$index]->sort_order;
            $categories[$index]->update(['sort_order' => $target->sort_order]);
            $target->update(['sort_order' => $currentOrder]);
        });
    }

    private function redirect(Account $account, FestivalEdition $edition): RedirectResponse
    {
        return redirect()->route('dashboard.accounts.festivals.settings.categories', [$account, $edition])->with('status', __('app.festival_category_saved'));
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

    private function assertCategory(Account $account, FestivalEdition $edition, FestivalCategory $category): void
    {
        $this->assertEdition($account, $edition);
        abort_unless($category->account_id === $account->id && $category->festival_edition_id === $edition->id, 404);
    }
}

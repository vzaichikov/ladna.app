<?php

namespace App\Http\Controllers;

use App\Enums\FestivalScoreSheetStatus;
use App\Http\Requests\FestivalMoveRequest;
use App\Http\Requests\FestivalRubricRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalResult;
use App\Models\FestivalRubric;
use App\Models\FestivalScoreSheet;
use App\Support\Festivals\FestivalSettingsOrder;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FestivalRubricController extends Controller
{
    public function __construct(
        private readonly FestivalWorkspaceAccess $workspaceAccess,
        private readonly FestivalSettingsOrder $settingsOrder,
    ) {}

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

        $rubrics = $festivalEdition->festivalRubrics()
            ->with(['category', 'sections' => fn ($query) => $query->withCount('criteria')])
            ->withCount('scoreSheets')
            ->when($filters['q'] !== '', fn ($query) => $query->where('name', 'like', '%'.$filters['q'].'%'))
            ->when($filters['status'] !== '', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->when($filters['category_id'], fn ($query, int $id) => $query->where('festival_category_id', $id))
            ->paginate(20)
            ->withQueryString();

        return view('festivals.staff.judging.criteria', [
            'account' => $account,
            'edition' => $festivalEdition,
            'rubrics' => $rubrics,
            'categories' => $categories,
            'filters' => $filters,
            'hasFilters' => $filters['q'] !== '' || $filters['status'] !== '' || $filters['category_id'] !== null,
            'workspacePermissions' => $permissions,
        ]);
    }

    public function create(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);

        return view('festivals.staff.judging.rubric-form', [
            'account' => $account,
            'edition' => $festivalEdition,
            'rubric' => new FestivalRubric(['is_active' => true]),
            'categories' => $festivalEdition->categories()->get(),
            'workspacePermissions' => $permissions,
        ]);
    }

    public function store(FestivalRubricRequest $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        $data = $request->validated();
        $this->assertRubricCategory($festivalEdition, $data['festival_category_id'] ?? null);

        DB::transaction(function () use ($account, $festivalEdition, $data): void {
            $rubric = $festivalEdition->festivalRubrics()->create([
                'account_id' => $account->id,
                'festival_category_id' => $data['festival_category_id'] ?? null,
                'name' => $data['name'],
                'is_active' => $data['is_active'] ?? true,
                'sort_order' => $this->settingsOrder->next($festivalEdition->festivalRubrics()),
            ]);
            $this->replaceRubricStructure($rubric, $data['sections']);
        }, 3);

        return $this->redirect($account, $festivalEdition);
    }

    public function edit(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalRubric $festivalRubric): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertRubric($account, $festivalEdition, $festivalRubric);
        $festivalRubric->load('sections.criteria');

        return view('festivals.staff.judging.rubric-form', [
            'account' => $account,
            'edition' => $festivalEdition,
            'rubric' => $festivalRubric,
            'categories' => $festivalEdition->categories()->get(),
            'workspacePermissions' => $permissions,
        ]);
    }

    public function update(FestivalRubricRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalRubric $festivalRubric): RedirectResponse
    {
        $this->assertRubric($account, $festivalEdition, $festivalRubric);
        $data = $request->validated();
        $this->assertRubricCategory($festivalEdition, $data['festival_category_id'] ?? null);

        DB::transaction(function () use ($account, $festivalEdition, $festivalRubric, $data): void {
            $rubric = FestivalRubric::query()->whereKey($festivalRubric->id)->lockForUpdate()->firstOrFail();
            $this->assertRubric($account, $festivalEdition, $rubric);
            $sheets = FestivalScoreSheet::query()->where('festival_rubric_id', $rubric->id)->with('entry')->lockForUpdate()->get();
            $sheetIds = $sheets->modelKeys();
            $categoryIds = $sheets->pluck('entry.festival_category_id')->filter()->unique()->values();

            if ($sheetIds !== []) {
                DB::table('festival_criterion_scores')->whereIn('festival_score_sheet_id', $sheetIds)->delete();
                FestivalScoreSheet::query()->whereKey($sheetIds)->update([
                    'status' => FestivalScoreSheetStatus::Draft->value,
                    'comments' => null,
                    'total_score' => 0,
                    'submitted_at' => null,
                ]);
            }

            if ($categoryIds->isNotEmpty()) {
                FestivalResult::query()->whereIn(
                    'festival_entry_id',
                    FestivalEntry::query()->select('id')->where('festival_edition_id', $festivalEdition->id)->whereIn('festival_category_id', $categoryIds),
                )->delete();
            }

            $rubric->sections()->with('criteria')->get()->each(function ($section): void {
                $section->criteria()->delete();
                $section->delete();
            });
            $rubric->update([
                'festival_category_id' => $data['festival_category_id'] ?? null,
                'name' => $data['name'],
                'is_active' => $data['is_active'] ?? false,
            ]);
            $this->replaceRubricStructure($rubric, $data['sections']);
        }, 3);

        return $this->redirect($account, $festivalEdition);
    }

    public function toggle(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalRubric $festivalRubric): RedirectResponse
    {
        $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertRubric($account, $festivalEdition, $festivalRubric);

        DB::transaction(function () use ($account, $festivalEdition, $festivalRubric): void {
            $rubric = FestivalRubric::query()->whereKey($festivalRubric->id)->lockForUpdate()->firstOrFail();
            $this->assertRubric($account, $festivalEdition, $rubric);
            $rubric->update(['is_active' => ! $rubric->is_active]);
        }, 3);

        return back()->with('status', __('app.festival_status_saved'));
    }

    public function move(FestivalMoveRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalRubric $festivalRubric): RedirectResponse
    {
        $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertRubric($account, $festivalEdition, $festivalRubric);
        $this->settingsOrder->move($festivalRubric, $festivalEdition->festivalRubrics(), $request->validated('direction'));

        return back()->with('status', __('app.festival_order_saved'));
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

    private function assertRubric(Account $account, FestivalEdition $edition, FestivalRubric $rubric): void
    {
        $this->assertEdition($account, $edition);
        abort_unless($rubric->account_id === $account->id && $rubric->festival_edition_id === $edition->id, 404);
    }

    private function assertRubricCategory(FestivalEdition $edition, ?int $categoryId): void
    {
        if ($categoryId !== null) {
            abort_unless($edition->categories()->whereKey($categoryId)->exists(), 422);
        }
    }

    /** @param array<int, array<string, mixed>> $sections */
    private function replaceRubricStructure(FestivalRubric $rubric, array $sections): void
    {
        foreach ($sections as $sectionIndex => $sectionData) {
            $section = $rubric->sections()->create([
                'account_id' => $rubric->account_id,
                'name' => $sectionData['name'],
                'weight' => $sectionData['weight'],
                'sort_order' => $sectionIndex,
            ]);

            foreach ($sectionData['criteria'] as $criterionIndex => $criterion) {
                $section->criteria()->create([
                    'account_id' => $rubric->account_id,
                    ...$criterion,
                    'sort_order' => $criterionIndex,
                ]);
            }
        }
    }

    private function redirect(Account $account, FestivalEdition $edition): RedirectResponse
    {
        return redirect()->route('dashboard.accounts.festivals.judging.criteria.index', [$account, $edition])
            ->with('status', __('app.festival_rubric_saved'));
    }
}

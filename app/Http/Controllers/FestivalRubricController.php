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
use App\Models\FestivalRubricCriterion;
use App\Models\FestivalRubricSection;
use App\Models\FestivalScoreSheet;
use App\Support\Festivals\FestivalJudgingCriteria;
use App\Support\Festivals\FestivalSettingsOrder;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FestivalRubricController extends Controller
{
    public function __construct(
        private readonly FestivalWorkspaceAccess $workspaceAccess,
        private readonly FestivalSettingsOrder $settingsOrder,
        private readonly FestivalJudgingCriteria $judgingCriteria,
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
            ->with(['category', 'sections' => fn ($query) => $query->withCount(['criteria', 'judgeAssignments'])])
            ->withCount('scoreSheets')
            ->when($filters['q'] !== '', fn ($query) => $query->where('name', 'like', '%'.$filters['q'].'%'))
            ->when($filters['status'] !== '', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->when($filters['category_id'], fn ($query, int $id) => $query->where('festival_category_id', $id))
            ->paginate(20)
            ->withQueryString();
        $assignments = $festivalEdition->judgeAssignments()
            ->where('is_active', true)
            ->with(['categories', 'rubricSections'])
            ->get();
        $rubrics->getCollection()->each(function (FestivalRubric $rubric) use ($assignments): void {
            $applicableAssignments = $rubric->festival_category_id === null
                ? $assignments
                : $assignments->filter(fn ($assignment): bool => $assignment->categories->contains('id', $rubric->festival_category_id));
            $rubric->loadMissing('sections.criteria');
            $rubric->setAttribute(
                'uncovered_section_names',
                $this->judgingCriteria->uncoveredSections($rubric, $applicableAssignments)->pluck('name')->all(),
            );
            $rubric->setAttribute(
                'can_delete',
                $rubric->score_sheets_count === 0
                    && $rubric->sections->every(fn (FestivalRubricSection $section): bool => $section->judge_assignments_count === 0),
            );
        });

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
            $this->syncRubricStructure($rubric, $data['sections']);
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
            $rubric->load('sections.criteria');
            $existingSectionIds = $rubric->sections->modelKeys();
            $submittedSectionIds = collect($data['sections'])->pluck('id')->filter()->map(fn (mixed $id): int => (int) $id)->all();
            $removedSectionIds = array_values(array_diff($existingSectionIds, $submittedSectionIds));

            if ($removedSectionIds !== [] && DB::table('festival_judge_assignment_rubric_section')->whereIn('festival_rubric_section_id', $removedSectionIds)->exists()) {
                throw ValidationException::withMessages(['sections' => __('app.festival_rubric_assigned_section_delete')]);
            }

            if ((int) $rubric->festival_category_id !== (int) ($data['festival_category_id'] ?? 0)
                && DB::table('festival_judge_assignment_rubric_section')->whereIn('festival_rubric_section_id', $existingSectionIds)->exists()) {
                throw ValidationException::withMessages(['festival_category_id' => __('app.festival_rubric_assigned_category_change')]);
            }

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

            $rubric->update([
                'festival_category_id' => $data['festival_category_id'] ?? null,
                'name' => $data['name'],
                'is_active' => $data['is_active'] ?? false,
            ]);
            $this->syncRubricStructure($rubric, $data['sections']);
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

    public function destroy(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalRubric $festivalRubric): RedirectResponse
    {
        $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertRubric($account, $festivalEdition, $festivalRubric);

        try {
            $deleted = DB::transaction(function () use ($account, $festivalEdition, $festivalRubric): bool {
                $edition = FestivalEdition::query()->whereKey($festivalEdition->id)->lockForUpdate()->firstOrFail();
                $this->assertEdition($account, $edition);
                $rubric = FestivalRubric::query()->whereKey($festivalRubric->id)->lockForUpdate()->firstOrFail();
                $this->assertRubric($account, $edition, $rubric);

                if ($rubric->scoreSheets()->exists() || $rubric->sections()->whereHas('judgeAssignments')->exists()) {
                    return false;
                }

                return (bool) $rubric->delete();
            }, 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '23000') {
                throw $exception;
            }

            $deleted = false;
        }

        if (! $deleted) {
            return $this->redirect($account, $festivalEdition)
                ->withErrors(['festival_rubric' => __('app.festival_rubric_delete_linked')]);
        }

        return redirect()->route('dashboard.accounts.festivals.judging.criteria.index', [$account, $festivalEdition])
            ->with('status', __('app.festival_rubric_deleted'));
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
    private function syncRubricStructure(FestivalRubric $rubric, array $sections): void
    {
        $retainedSectionIds = [];

        foreach ($sections as $sectionIndex => $sectionData) {
            $section = isset($sectionData['id'])
                ? FestivalRubricSection::query()->where('festival_rubric_id', $rubric->id)->findOrFail((int) $sectionData['id'])
                : new FestivalRubricSection(['account_id' => $rubric->account_id, 'festival_rubric_id' => $rubric->id]);
            $section->fill([
                'name' => $sectionData['name'],
                'weight' => $sectionData['weight'],
                'contribution' => $sectionData['contribution'],
                'sort_order' => $sectionIndex,
            ])->save();
            $retainedSectionIds[] = $section->id;
            $retainedCriterionIds = [];

            foreach ($sectionData['criteria'] as $criterionIndex => $criterionData) {
                $criterion = isset($criterionData['id'])
                    ? FestivalRubricCriterion::query()->where('festival_rubric_section_id', $section->id)->findOrFail((int) $criterionData['id'])
                    : new FestivalRubricCriterion(['account_id' => $rubric->account_id, 'festival_rubric_section_id' => $section->id]);
                $criterion->fill([
                    'name' => $criterionData['name'],
                    'max_score' => $criterionData['max_score'],
                    'weight' => $criterionData['weight'],
                    'sort_order' => $criterionIndex,
                ])->save();
                $retainedCriterionIds[] = $criterion->id;
            }

            $section->criteria()->whereNotIn('id', $retainedCriterionIds)->delete();
        }

        $rubric->sections()->whereNotIn('id', $retainedSectionIds)->delete();
    }

    private function redirect(Account $account, FestivalEdition $edition): RedirectResponse
    {
        return redirect()->route('dashboard.accounts.festivals.judging.criteria.index', [$account, $edition])
            ->with('status', __('app.festival_rubric_saved'));
    }
}

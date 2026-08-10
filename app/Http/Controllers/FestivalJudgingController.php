<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\SaveFestivalScoreSheet;
use App\Http\Requests\FestivalRubricRequest;
use App\Http\Requests\FestivalScoreSheetRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalPortalUser;
use App\Models\FestivalResult;
use App\Models\FestivalRubric;
use App\Models\FestivalScoreSheet;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FestivalJudgingController extends Controller
{
    public function index(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkspaceAccess $workspaceAccess): View
    {
        $this->assertEdition($account, $festivalEdition);
        $permissions = $workspaceAccess->permissions($request->user(), $account, $festivalEdition);
        abort_unless($permissions['judging'], 403);
        $festivalEdition->load('categories');
        $assignment = FestivalJudgeAssignment::query()->where('festival_edition_id', $festivalEdition->id)->where('user_id', $request->user()->id)->where('is_active', true)->first();

        return view('festivals.staff.judging', [
            'account' => $account,
            'edition' => $festivalEdition,
            'assignments' => FestivalJudgeAssignment::query()->where('festival_edition_id', $festivalEdition->id)->with(['user', 'portalUser', 'categories'])->get(),
            'rubrics' => FestivalRubric::query()->where('festival_edition_id', $festivalEdition->id)->with(['sections.criteria', 'category'])->get(),
            'assignment' => $assignment,
            'sheets' => $assignment?->scoreSheets()->with(['entry.category', 'rubric'])->get() ?? collect(),
            'workspacePermissions' => $permissions,
        ]);
    }

    public function storeAssignment(Request $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        abort_unless($request->user()?->can('manageFestivals', $account), 403);
        $data = $request->validate([
            'user_id' => ['nullable', 'integer'], 'festival_portal_user_id' => ['nullable', 'integer'],
            'display_name' => ['required', 'string', 'max:255'], 'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['integer', 'distinct'], 'is_head_judge' => ['sometimes', 'boolean'],
        ]);
        if ((isset($data['user_id']) ? 1 : 0) + (isset($data['festival_portal_user_id']) ? 1 : 0) !== 1) {
            throw ValidationException::withMessages(['user_id' => __('app.festival_judge_identity_required')]);
        }
        if (isset($data['user_id'])) {
            abort_unless($account->users()->whereKey($data['user_id'])->exists(), 422);
        } else {
            abort_unless(FestivalPortalUser::query()->whereKey($data['festival_portal_user_id'])->whereBelongsTo($account)->exists(), 422);
        }
        $categories = $festivalEdition->categories()->whereKey($data['category_ids'])->get();
        abort_unless($categories->count() === count($data['category_ids']), 422);
        $assignment = FestivalJudgeAssignment::query()->create([
            'account_id' => $account->id, 'festival_edition_id' => $festivalEdition->id,
            'user_id' => $data['user_id'] ?? null, 'festival_portal_user_id' => $data['festival_portal_user_id'] ?? null,
            'display_name' => $data['display_name'], 'is_head_judge' => $data['is_head_judge'] ?? false,
        ]);
        $assignment->categories()->sync($categories->mapWithKeys(fn ($category): array => [$category->id => ['account_id' => $account->id]])->all());

        return redirect()->route('dashboard.accounts.festivals.judging.index', [$account, $festivalEdition])->with('status', __('app.festival_judge_saved'));
    }

    public function storeRubric(FestivalRubricRequest $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        $data = $request->validated();
        $this->assertRubricCategory($festivalEdition, $data['festival_category_id'] ?? null);
        DB::transaction(function () use ($account, $festivalEdition, $data): void {
            $rubric = FestivalRubric::query()->create([
                'account_id' => $account->id,
                'festival_edition_id' => $festivalEdition->id,
                'festival_category_id' => $data['festival_category_id'] ?? null,
                'name' => $data['name'],
                'is_active' => $data['is_active'] ?? true,
                'sort_order' => ((int) FestivalRubric::query()->where('festival_edition_id', $festivalEdition->id)->max('sort_order')) + 10,
            ]);
            $this->replaceRubricStructure($rubric, $data['sections']);
        });

        return redirect()->route('dashboard.accounts.festivals.judging.index', [$account, $festivalEdition])->with('status', __('app.festival_rubric_saved'));
    }

    public function updateRubric(FestivalRubricRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalRubric $festivalRubric): RedirectResponse
    {
        $this->assertRubric($account, $festivalEdition, $festivalRubric);
        $data = $request->validated();
        $this->assertRubricCategory($festivalEdition, $data['festival_category_id'] ?? null);

        DB::transaction(function () use ($festivalRubric, $data): void {
            $rubric = FestivalRubric::query()->whereKey($festivalRubric->id)->lockForUpdate()->firstOrFail();
            $sheets = FestivalScoreSheet::query()->where('festival_rubric_id', $rubric->id)->with('entry')->lockForUpdate()->get();
            $sheetIds = $sheets->modelKeys();
            $categoryIds = $sheets->pluck('entry.festival_category_id')->filter()->unique()->values();

            if ($sheetIds !== []) {
                DB::table('festival_criterion_scores')->whereIn('festival_score_sheet_id', $sheetIds)->delete();
                FestivalScoreSheet::query()->whereKey($sheetIds)->update([
                    'status' => 'draft',
                    'comments' => null,
                    'total_score' => 0,
                    'submitted_at' => null,
                ]);
            }
            if ($categoryIds->isNotEmpty()) {
                FestivalResult::query()->whereIn('festival_entry_id', FestivalEntry::query()->select('id')->whereIn('festival_category_id', $categoryIds))->delete();
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

        return redirect()->route('dashboard.accounts.festivals.judging.index', [$account, $festivalEdition])->with('status', __('app.festival_rubric_saved'));
    }

    public function prepareSheets(Request $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        abort_unless($request->user()?->can('manageFestivals', $account), 403);
        $assignments = FestivalJudgeAssignment::query()->where('festival_edition_id', $festivalEdition->id)->where('is_active', true)->with('categories')->get();
        $created = 0;
        DB::transaction(function () use ($festivalEdition, $assignments, &$created): void {
            foreach ($assignments as $assignment) {
                foreach ($assignment->categories as $category) {
                    $rubric = FestivalRubric::query()->where('festival_edition_id', $festivalEdition->id)->where('is_active', true)->where(fn ($query) => $query->where('festival_category_id', $category->id)->orWhereNull('festival_category_id'))->orderByRaw('festival_category_id is null')->orderBy('sort_order')->orderBy('id')->first();
                    if (! $rubric) {
                        continue;
                    }
                    foreach (FestivalEntry::query()->where('festival_category_id', $category->id)->where('status', 'accepted')->get() as $entry) {
                        $sheet = FestivalScoreSheet::query()->firstOrCreate(['festival_entry_id' => $entry->id, 'festival_judge_assignment_id' => $assignment->id], ['account_id' => $festivalEdition->account_id, 'festival_rubric_id' => $rubric->id]);
                        $created += $sheet->wasRecentlyCreated ? 1 : 0;
                    }
                }
            }
        });

        return redirect()->route('dashboard.accounts.festivals.judging.index', [$account, $festivalEdition])->with('status', __('app.festival_score_sheets_prepared', ['count' => $created]));
    }

    public function editStaff(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalScoreSheet $festivalScoreSheet): View
    {
        $assignment = $this->staffAssignment($request, $account, $festivalEdition);

        return $this->sheetView($account, $festivalEdition, $festivalScoreSheet, $assignment, false);
    }

    public function updateStaff(FestivalScoreSheetRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalScoreSheet $festivalScoreSheet, SaveFestivalScoreSheet $save): RedirectResponse
    {
        $assignment = $this->staffAssignment($request, $account, $festivalEdition);
        $save->execute($festivalScoreSheet, $assignment, $request->validated(), $request->user());

        return back()->with('status', __('app.festival_score_saved'));
    }

    public function guestIndex(Request $request, string $accountSlug, string $editionSlug): View
    {
        [$account, $edition, $assignment] = $this->guestAssignment($request, $accountSlug, $editionSlug);

        return view('festivals.portal.judging', ['account' => $account, 'edition' => $edition, 'assignment' => $assignment, 'sheets' => $assignment->scoreSheets()->with(['entry.category', 'rubric'])->get()]);
    }

    public function editGuest(Request $request, string $accountSlug, string $editionSlug, FestivalScoreSheet $festivalScoreSheet): View
    {
        [$account, $edition, $assignment] = $this->guestAssignment($request, $accountSlug, $editionSlug);

        return $this->sheetView($account, $edition, $festivalScoreSheet, $assignment, true);
    }

    public function updateGuest(FestivalScoreSheetRequest $request, string $accountSlug, string $editionSlug, FestivalScoreSheet $festivalScoreSheet, SaveFestivalScoreSheet $save): RedirectResponse
    {
        [, , $assignment] = $this->guestAssignment($request, $accountSlug, $editionSlug);
        $save->execute($festivalScoreSheet, $assignment, $request->validated(), $request->user('festival'));

        return back()->with('status', __('app.festival_score_saved'));
    }

    private function sheetView(Account $account, FestivalEdition $edition, FestivalScoreSheet $sheet, FestivalJudgeAssignment $assignment, bool $guest): View
    {
        abort_unless($sheet->festival_judge_assignment_id === $assignment->id && $sheet->entry()->where('festival_edition_id', $edition->id)->exists(), 404);
        $sheet->load(['entry.participants', 'rubric.sections.criteria', 'scores']);

        return view('festivals.shared.score-sheet', compact('account', 'edition', 'sheet', 'assignment', 'guest'));
    }

    private function staffAssignment(Request $request, Account $account, FestivalEdition $edition): FestivalJudgeAssignment
    {
        $this->assertEdition($account, $edition);
        abort_unless($request->user()?->can('judgeFestivals', $account), 403);

        return FestivalJudgeAssignment::query()->where('festival_edition_id', $edition->id)->where('user_id', $request->user()->id)->where('is_active', true)->firstOrFail();
    }

    /** @return array{Account, FestivalEdition, FestivalJudgeAssignment} */
    private function guestAssignment(Request $request, string $accountSlug, string $editionSlug): array
    {
        $account = $request->attributes->get('festivalAccount');
        $portalUser = $request->user('festival');
        abort_unless($account instanceof Account && $account->slug === $accountSlug && $portalUser instanceof FestivalPortalUser && $portalUser->account_id === $account->id, 404);
        $edition = FestivalEdition::query()->whereBelongsTo($account)->where('slug', $editionSlug)->firstOrFail();
        $assignment = FestivalJudgeAssignment::query()->where('festival_edition_id', $edition->id)->where('festival_portal_user_id', $portalUser->id)->where('is_active', true)->firstOrFail();

        return [$account, $edition, $assignment];
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
            $section = $rubric->sections()->create(['account_id' => $rubric->account_id, 'name' => $sectionData['name'], 'weight' => $sectionData['weight'], 'sort_order' => $sectionIndex]);
            foreach ($sectionData['criteria'] as $criterionIndex => $criterion) {
                $section->criteria()->create(['account_id' => $rubric->account_id, ...$criterion, 'sort_order' => $criterionIndex]);
            }
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\SaveFestivalScoreSheet;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalScoreSheetStatus;
use App\Http\Requests\FestivalScoreSheetRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRubric;
use App\Models\FestivalScoreSheet;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FestivalJudgingController extends Controller
{
    public function index(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkspaceAccess $workspaceAccess): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        $permissions = $workspaceAccess->permissions($request->user(), $account, $festivalEdition);
        abort_unless($permissions['judging'], 403);

        if ($permissions['manage']) {
            return redirect()->route('dashboard.accounts.festivals.judging.judges.index', [$account, $festivalEdition]);
        }

        return redirect()->route('dashboard.accounts.festivals.judging.score-sheets.index', [$account, $festivalEdition]);
    }

    public function scoreSheets(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkspaceAccess $workspaceAccess): View
    {
        $this->assertEdition($account, $festivalEdition);
        $permissions = $workspaceAccess->permissions($request->user(), $account, $festivalEdition);
        abort_unless($permissions['judging'], 403);

        $assignment = $request->user()?->can('judgeFestivals', $account)
            ? FestivalJudgeAssignment::query()
                ->where('festival_edition_id', $festivalEdition->id)
                ->where('user_id', $request->user()->id)
                ->where('is_active', true)
                ->first()
            : null;
        $categories = $assignment?->categories()->orderBy('sort_order')->orderBy('id')->get() ?? collect();
        $categoryId = $request->integer('category_id');
        $filters = [
            'q' => $request->string('q')->trim()->toString(),
            'status' => in_array($request->query('status'), array_column(FestivalScoreSheetStatus::cases(), 'value'), true) ? $request->query('status') : '',
            'category_id' => $categories->contains('id', $categoryId) ? $categoryId : null,
        ];

        $sheets = ($assignment?->scoreSheets() ?? FestivalScoreSheet::query()->whereRaw('1 = 0'))
            ->with(['entry.category', 'rubric'])
            ->when($filters['q'] !== '', fn ($query) => $query->whereHas('entry', fn ($entryQuery) => $entryQuery->where('entry_name', 'like', '%'.$filters['q'].'%')))
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['category_id'], fn ($query, int $id) => $query->whereHas('entry', fn ($entryQuery) => $entryQuery->where('festival_category_id', $id)))
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return view('festivals.staff.judging.score-sheets', [
            'account' => $account,
            'edition' => $festivalEdition,
            'assignment' => $assignment,
            'sheets' => $sheets,
            'categories' => $categories,
            'filters' => $filters,
            'hasFilters' => $filters['q'] !== '' || $filters['status'] !== '' || $filters['category_id'] !== null,
            'workspacePermissions' => $permissions,
        ]);
    }

    public function prepareSheets(Request $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        abort_unless($request->user()?->can('manageFestivals', $account), 403);
        $assignments = FestivalJudgeAssignment::query()
            ->where('festival_edition_id', $festivalEdition->id)
            ->where('is_active', true)
            ->with('categories')
            ->get();
        $created = 0;

        DB::transaction(function () use ($festivalEdition, $assignments, &$created): void {
            foreach ($assignments as $assignment) {
                foreach ($assignment->categories as $category) {
                    $rubric = FestivalRubric::query()
                        ->where('festival_edition_id', $festivalEdition->id)
                        ->where('is_active', true)
                        ->where(fn ($query) => $query->where('festival_category_id', $category->id)->orWhereNull('festival_category_id'))
                        ->orderByRaw('festival_category_id is null')
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->first();

                    if (! $rubric) {
                        continue;
                    }

                    foreach (FestivalEntry::query()->where('festival_category_id', $category->id)->where('status', FestivalEntryStatus::Accepted->value)->get() as $entry) {
                        $sheet = FestivalScoreSheet::query()->firstOrCreate(
                            [
                                'festival_entry_id' => $entry->id,
                                'festival_judge_assignment_id' => $assignment->id,
                            ],
                            [
                                'account_id' => $festivalEdition->account_id,
                                'festival_rubric_id' => $rubric->id,
                            ],
                        );
                        $created += $sheet->wasRecentlyCreated ? 1 : 0;
                    }
                }
            }
        }, 3);

        return redirect()->route('dashboard.accounts.festivals.judging.score-sheets.index', [$account, $festivalEdition])
            ->with('status', __('app.festival_score_sheets_prepared', ['count' => $created]));
    }

    public function legacyEditStaff(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalScoreSheet $festivalScoreSheet): RedirectResponse
    {
        $assignment = $this->staffAssignment($request, $account, $festivalEdition);
        $this->assertOwnedSheet($festivalEdition, $festivalScoreSheet, $assignment);

        return redirect()->route('dashboard.accounts.festivals.judging.score-sheets.edit', [$account, $festivalEdition, $festivalScoreSheet]);
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
        $this->assertOwnedSheet($edition, $sheet, $assignment);
        $sheet->load(['entry.participants', 'rubric.sections.criteria', 'scores']);

        return view('festivals.shared.score-sheet', compact('account', 'edition', 'sheet', 'assignment', 'guest'));
    }

    private function assertOwnedSheet(FestivalEdition $edition, FestivalScoreSheet $sheet, FestivalJudgeAssignment $assignment): void
    {
        abort_unless($sheet->festival_judge_assignment_id === $assignment->id && $sheet->entry()->where('festival_edition_id', $edition->id)->exists(), 404);
    }

    private function staffAssignment(Request $request, Account $account, FestivalEdition $edition): FestivalJudgeAssignment
    {
        $this->assertEdition($account, $edition);
        abort_unless($request->user()?->can('judgeFestivals', $account), 403);

        return FestivalJudgeAssignment::query()
            ->where('festival_edition_id', $edition->id)
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->firstOrFail();
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
}

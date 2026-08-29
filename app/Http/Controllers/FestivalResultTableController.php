<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\BuildFestivalResults;
use App\Actions\Festivals\SaveFestivalPenalty;
use App\Actions\Festivals\SaveFestivalScoreSheet;
use App\Enums\FestivalCompetitionFormat;
use App\Enums\FestivalEntryStatus;
use App\Http\Requests\FestivalPenaltyRequest;
use App\Http\Requests\FestivalScoreSheetRequest;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalPenalty;
use App\Models\FestivalPortalUser;
use App\Models\FestivalScoreSheet;
use App\Support\Festivals\FestivalJudgingCriteria;
use App\Support\Festivals\FestivalResultTableAccess;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FestivalResultTableController extends Controller
{
    public function __construct(
        private readonly FestivalResultTableAccess $access,
        private readonly FestivalWorkspaceAccess $workspaceAccess,
        private readonly FestivalJudgingCriteria $judgingCriteria,
        private readonly BuildFestivalResults $results,
    ) {}

    public function showStaff(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalCategory $festivalCategory): View
    {
        $this->assertCategory($account, $festivalEdition, $festivalCategory);
        abort_unless($this->access->canStaffView($request->user(), $account, $festivalEdition), 403);
        $headAssignment = $this->access->staffAssignment($request->user(), $festivalEdition);
        abort_unless($this->access->categoryAllowed($headAssignment, $festivalCategory), 403);

        return view('festivals.staff.judging.result-table', $this->tableData(
            $request,
            $account,
            $festivalEdition,
            $festivalCategory,
            $this->access->canStaffEdit($request->user(), $account, $festivalEdition),
            false,
        ) + ['workspacePermissions' => $this->workspaceAccess->permissions($request->user(), $account, $festivalEdition)]);
    }

    public function updateScoreStaff(FestivalScoreSheetRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalCategory $festivalCategory, FestivalScoreSheet $festivalScoreSheet, SaveFestivalScoreSheet $save): JsonResponse|RedirectResponse
    {
        $this->assertStaffEdit($request, $account, $festivalEdition, $festivalCategory);
        $this->assertSheet($festivalCategory, $festivalScoreSheet);
        $festivalScoreSheet->loadMissing('assignment');
        $sheet = $save->execute($festivalScoreSheet, $festivalScoreSheet->assignment, $request->validated(), $request->user());

        return $this->scoreResponse($request, $account, $festivalEdition, $festivalCategory, $sheet, false);
    }

    public function storePenaltyStaff(FestivalPenaltyRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalCategory $festivalCategory, SaveFestivalPenalty $save): JsonResponse|RedirectResponse
    {
        $this->assertStaffEdit($request, $account, $festivalEdition, $festivalCategory);
        $entry = $this->entry($festivalCategory, (int) $request->validated('festival_entry_id'));
        $save->execute($entry, null, $request->validated(), $request->user());

        return $this->mutationResponse($request, __('app.festival_penalty_saved'));
    }

    public function updatePenaltyStaff(FestivalPenaltyRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalCategory $festivalCategory, FestivalPenalty $festivalPenalty, SaveFestivalPenalty $save): JsonResponse|RedirectResponse
    {
        $this->assertStaffEdit($request, $account, $festivalEdition, $festivalCategory);
        $entry = $this->entry($festivalCategory, (int) $request->validated('festival_entry_id'));
        abort_unless($festivalPenalty->account_id === $account->id && $festivalPenalty->festival_entry_id === $entry->id, 404);
        $save->execute($entry, $festivalPenalty, $request->validated(), $request->user());

        return $this->mutationResponse($request, __('app.festival_penalty_saved'));
    }

    public function destroyPenaltyStaff(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalCategory $festivalCategory, FestivalPenalty $festivalPenalty, SaveFestivalPenalty $save): JsonResponse|RedirectResponse
    {
        $this->assertStaffEdit($request, $account, $festivalEdition, $festivalCategory);
        $festivalPenalty->loadMissing('entry');
        abort_unless($festivalPenalty->account_id === $account->id && $festivalPenalty->entry->festival_category_id === $festivalCategory->id, 404);
        $save->delete($festivalPenalty, $request->user());

        return $this->mutationResponse($request, __('app.festival_penalty_deleted'));
    }

    public function showPortal(Request $request, string $accountSlug, FestivalEdition $festivalEdition, FestivalCategory $festivalCategory): View
    {
        [$account, $portalUser, $headAssignment] = $this->portalContext($request, $accountSlug, $festivalEdition);
        $this->assertCategory($account, $festivalEdition, $festivalCategory);
        abort_unless($this->access->categoryAllowed($headAssignment, $festivalCategory), 403);

        return view('festivals.portal.result-table', $this->tableData($request, $account, $festivalEdition, $festivalCategory, true, true) + [
            'portalUser' => $portalUser,
        ]);
    }

    public function updateScorePortal(FestivalScoreSheetRequest $request, string $accountSlug, FestivalEdition $festivalEdition, FestivalCategory $festivalCategory, FestivalScoreSheet $festivalScoreSheet, SaveFestivalScoreSheet $save): JsonResponse|RedirectResponse
    {
        [$account, $portalUser, $headAssignment] = $this->portalContext($request, $accountSlug, $festivalEdition);
        $this->assertCategory($account, $festivalEdition, $festivalCategory);
        abort_unless($this->access->categoryAllowed($headAssignment, $festivalCategory), 403);
        $this->assertSheet($festivalCategory, $festivalScoreSheet);
        $festivalScoreSheet->loadMissing('assignment');
        $sheet = $save->execute($festivalScoreSheet, $festivalScoreSheet->assignment, $request->validated(), $portalUser);

        return $this->scoreResponse($request, $account, $festivalEdition, $festivalCategory, $sheet, true);
    }

    public function storePenaltyPortal(FestivalPenaltyRequest $request, string $accountSlug, FestivalEdition $festivalEdition, FestivalCategory $festivalCategory, SaveFestivalPenalty $save): JsonResponse|RedirectResponse
    {
        [$account, $portalUser, $headAssignment] = $this->portalContext($request, $accountSlug, $festivalEdition);
        $this->assertCategory($account, $festivalEdition, $festivalCategory);
        abort_unless($this->access->categoryAllowed($headAssignment, $festivalCategory), 403);
        $entry = $this->entry($festivalCategory, (int) $request->validated('festival_entry_id'));
        $save->execute($entry, null, $request->validated(), $portalUser);

        return $this->mutationResponse($request, __('app.festival_penalty_saved'));
    }

    public function updatePenaltyPortal(FestivalPenaltyRequest $request, string $accountSlug, FestivalEdition $festivalEdition, FestivalCategory $festivalCategory, FestivalPenalty $festivalPenalty, SaveFestivalPenalty $save): JsonResponse|RedirectResponse
    {
        [$account, $portalUser, $headAssignment] = $this->portalContext($request, $accountSlug, $festivalEdition);
        $this->assertCategory($account, $festivalEdition, $festivalCategory);
        abort_unless($this->access->categoryAllowed($headAssignment, $festivalCategory), 403);
        $entry = $this->entry($festivalCategory, (int) $request->validated('festival_entry_id'));
        abort_unless($festivalPenalty->account_id === $account->id && $festivalPenalty->festival_entry_id === $entry->id, 404);
        $save->execute($entry, $festivalPenalty, $request->validated(), $portalUser);

        return $this->mutationResponse($request, __('app.festival_penalty_saved'));
    }

    public function destroyPenaltyPortal(Request $request, string $accountSlug, FestivalEdition $festivalEdition, FestivalCategory $festivalCategory, FestivalPenalty $festivalPenalty, SaveFestivalPenalty $save): JsonResponse|RedirectResponse
    {
        [$account, $portalUser, $headAssignment] = $this->portalContext($request, $accountSlug, $festivalEdition);
        $this->assertCategory($account, $festivalEdition, $festivalCategory);
        abort_unless($this->access->categoryAllowed($headAssignment, $festivalCategory), 403);
        $festivalPenalty->loadMissing('entry');
        abort_unless($festivalPenalty->account_id === $account->id && $festivalPenalty->entry->festival_category_id === $festivalCategory->id, 404);
        $save->delete($festivalPenalty, $portalUser);

        return $this->mutationResponse($request, __('app.festival_penalty_deleted'));
    }

    /** @return array<string, mixed> */
    private function tableData(Request $request, Account $account, FestivalEdition $edition, FestivalCategory $category, bool $editable, bool $guest): array
    {
        $results = $this->results->execute($edition, $category);
        $assignments = $results['rubric']
            ? $this->judgingCriteria->activeAssignments($category)
                ->filter(fn (FestivalJudgeAssignment $assignment): bool => $this->judgingCriteria->sectionsFor($assignment, $results['rubric'])->isNotEmpty())
                ->values()
            : collect();
        $requestedTab = $request->string('tab')->toString();
        $activeAssignment = $assignments->first(fn (FestivalJudgeAssignment $assignment): bool => $requestedTab === 'judge-'.$assignment->id);
        $activeTab = $activeAssignment ? $requestedTab : ($requestedTab === 'penalties' ? 'penalties' : 'summary');
        $criteria = $activeAssignment && $results['rubric']
            ? $this->judgingCriteria->sectionsFor($activeAssignment, $results['rubric'])->flatMap->criteria->values()
            : collect();

        return compact('account', 'edition', 'category', 'results', 'assignments', 'activeAssignment', 'activeTab', 'criteria', 'editable', 'guest');
    }

    private function assertStaffEdit(Request $request, Account $account, FestivalEdition $edition, FestivalCategory $category): void
    {
        $this->assertCategory($account, $edition, $category);
        abort_unless($this->access->canStaffEdit($request->user(), $account, $edition), 403);
        abort_unless($this->access->categoryAllowed($this->access->staffAssignment($request->user(), $edition), $category), 403);
    }

    /** @return array{Account, FestivalPortalUser, FestivalJudgeAssignment} */
    private function portalContext(Request $request, string $accountSlug, FestivalEdition $edition): array
    {
        $account = $request->attributes->get('festivalAccount');
        $portalUser = $request->user('festival');
        abort_unless($account instanceof Account && $account->slug === $accountSlug && $portalUser instanceof FestivalPortalUser, 404);
        abort_unless($edition->account_id === $account->id, 404);
        $assignment = $this->access->portalAssignment($portalUser, $edition);
        abort_unless($assignment, 403);

        return [$account, $portalUser, $assignment];
    }

    private function assertCategory(Account $account, FestivalEdition $edition, FestivalCategory $category): void
    {
        abort_unless(
            $edition->account_id === $account->id
                && $category->account_id === $account->id
                && $category->festival_edition_id === $edition->id
                && $category->competition_format === FestivalCompetitionFormat::Scored,
            404,
        );
    }

    private function assertSheet(FestivalCategory $category, FestivalScoreSheet $sheet): void
    {
        $rubric = $this->judgingCriteria->rubricForCategory($category->edition, $category);
        abort_unless(
            $rubric
                && $sheet->festival_rubric_id === $rubric->id
                && $sheet->entry()->where('festival_category_id', $category->id)->where('status', FestivalEntryStatus::Accepted->value)->exists()
                && $sheet->assignment()->where('is_active', true)->whereHas('categories', fn ($query) => $query->whereKey($category->id))->exists(),
            404,
        );
    }

    private function entry(FestivalCategory $category, int $entryId): FestivalEntry
    {
        return FestivalEntry::query()
            ->whereKey($entryId)
            ->where('account_id', $category->account_id)
            ->where('festival_edition_id', $category->festival_edition_id)
            ->where('festival_category_id', $category->id)
            ->where('status', FestivalEntryStatus::Accepted->value)
            ->firstOrFail();
    }

    private function scoreResponse(Request $request, Account $account, FestivalEdition $edition, FestivalCategory $category, FestivalScoreSheet $sheet, bool $guest): JsonResponse|RedirectResponse
    {
        if (! $request->expectsJson()) {
            return back()->with('status', __('app.festival_score_saved'));
        }

        return response()->json([
            'message' => __('app.festival_score_saved'),
            'sheet_id' => $sheet->id,
            'sheet_total' => $sheet->total_score,
            'summary_html' => view('festivals.shared.result-table._summary-rows', [
                'results' => $this->results->execute($edition, $category),
            ])->render(),
            'guest' => $guest,
        ]);
    }

    private function mutationResponse(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'reload' => true]);
        }

        return back()->with('status', $message);
    }
}

<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\SaveFestivalScoreSheet;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalPortalRole;
use App\Enums\FestivalScoreSheetStatus;
use App\Http\Requests\FestivalScoreSheetRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalPortalUser;
use App\Models\FestivalScoreSheet;
use App\Support\Festivals\FestivalJudgingCriteria;
use App\Support\Festivals\FestivalTimelinePresenter;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class FestivalJudgingController extends Controller
{
    public function __construct(
        private readonly FestivalJudgingCriteria $judgingCriteria,
        private readonly FestivalTimelinePresenter $timelinePresenter,
    ) {}

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

    public function scoreSheets(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalWorkspaceAccess $workspaceAccess): View|Response
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
        $categories = $permissions['manage']
            ? $festivalEdition->categories()->orderBy('sort_order')->orderBy('id')->get()
            : ($assignment?->categories()->orderBy('sort_order')->orderBy('id')->get() ?? collect());
        $categoryId = $request->integer('category_id');
        $filters = [
            'q' => $permissions['manage'] ? '' : $request->string('q')->trim()->toString(),
            'status' => in_array($request->query('status'), array_column(FestivalScoreSheetStatus::cases(), 'value'), true) ? $request->query('status') : '',
            'category_id' => $categories->contains('id', $categoryId) ? $categoryId : null,
        ];

        $sheetQuery = $permissions['manage']
            ? FestivalScoreSheet::query()->whereHas('entry', fn ($query) => $query->where('festival_edition_id', $festivalEdition->id))
            : ($assignment?->scoreSheets() ?? FestivalScoreSheet::query()->whereRaw('1 = 0'));
        $sheets = $sheetQuery
            ->with([
                'entry.category.direction',
                'entry.participants.portalUser',
                'rubric.sections.criteria',
                'assignment.rubricSections',
                'scores',
            ])
            ->when($filters['q'] !== '', fn ($query) => $query->whereHas('entry', fn ($entryQuery) => $entryQuery->where('entry_name', 'like', '%'.$filters['q'].'%')))
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['category_id'], fn ($query, int $id) => $query->whereHas('entry', fn ($entryQuery) => $entryQuery->where('festival_category_id', $id)))
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        $judgeMode = $assignment && ! $permissions['manage'];
        $listData = $judgeMode
            ? $this->judgeListData($festivalEdition, $sheets->getCollection(), $assignment)
            : [];
        $managerProgress = $permissions['manage']
            ? $sheets->getCollection()->mapWithKeys(fn (FestivalScoreSheet $sheet): array => [
                $sheet->id => $this->judgingCriteria->scoreProgress($sheet, $sheet->assignment),
            ])
            : collect();

        if ($judgeMode && $request->boolean('fragment')) {
            return response()
                ->view('festivals.shared._judge-list', [
                    'account' => $account,
                    'edition' => $festivalEdition,
                    'assignment' => $assignment,
                    'sheets' => $sheets,
                    'guest' => false,
                    'fragmentUrl' => $request->fullUrlWithQuery(['fragment' => 1]),
                    ...$listData,
                ])
                ->header('Cache-Control', 'private, no-store, max-age=0')
                ->header('Pragma', 'no-cache');
        }

        return view('festivals.staff.judging.score-sheets', [
            'account' => $account,
            'edition' => $festivalEdition,
            'assignment' => $assignment,
            'sheets' => $sheets,
            'categories' => $categories,
            'filters' => $filters,
            'hasFilters' => $filters['q'] !== '' || $filters['status'] !== '' || $filters['category_id'] !== null,
            'workspacePermissions' => $permissions,
            'guest' => false,
            'fragmentUrl' => $request->fullUrlWithQuery(['fragment' => 1]),
            'managerProgress' => $managerProgress,
            ...$listData,
        ]);
    }

    public function prepareSheets(Request $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        abort_unless($request->user()?->can('manageFestivals', $account), 403);
        $created = 0;

        DB::transaction(function () use ($festivalEdition, &$created): void {
            FestivalEdition::query()->whereKey($festivalEdition->id)->lockForUpdate()->firstOrFail();

            foreach ($festivalEdition->categories()->get() as $category) {
                $entries = FestivalEntry::query()
                    ->where('festival_category_id', $category->id)
                    ->where('status', FestivalEntryStatus::Accepted->value)
                    ->lockForUpdate()
                    ->get();

                if ($entries->isEmpty()) {
                    continue;
                }

                if ($entries->count() < $category->minimum_entries_to_run) {
                    throw ValidationException::withMessages([
                        'category' => __('app.festival_category_minimum_entries_required', ['minimum' => $category->minimum_entries_to_run]),
                    ]);
                }

                $rubric = $this->judgingCriteria->rubricForCategory($festivalEdition, $category);

                if (! $rubric) {
                    continue;
                }

                $rubric->load('sections.criteria');
                $assignments = $this->judgingCriteria->activeAssignments($category);
                $uncoveredSections = $this->judgingCriteria->uncoveredSections($rubric, $assignments);

                if ($uncoveredSections->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'category' => __('app.festival_results_criteria_uncovered', ['sections' => $uncoveredSections->pluck('name')->join(', ')]),
                    ]);
                }

                foreach ($assignments as $assignment) {
                    if ($this->judgingCriteria->sectionsFor($assignment, $rubric)->isEmpty()) {
                        continue;
                    }

                    foreach ($entries as $entry) {
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

    public function updateStaff(FestivalScoreSheetRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalScoreSheet $festivalScoreSheet, SaveFestivalScoreSheet $save): JsonResponse|RedirectResponse
    {
        $assignment = $this->staffAssignment($request, $account, $festivalEdition);
        $sheet = $save->execute($festivalScoreSheet, $assignment, $request->validated(), $request->user());

        return $this->scoreSaveResponse($request, $sheet, $assignment);
    }

    public function guestIndex(Request $request, string $accountSlug, FestivalEdition $festivalEdition): View|Response
    {
        [$account, $edition, $assignment] = $this->guestAssignment($request, $accountSlug, $festivalEdition);
        $sheets = $assignment->scoreSheets()
            ->with([
                'entry.category.direction',
                'entry.participants.portalUser',
                'rubric.sections.criteria',
                'assignment.rubricSections',
                'scores',
            ])
            ->get();
        $data = [
            'account' => $account,
            'edition' => $edition,
            'assignment' => $assignment,
            'sheets' => $sheets,
            'guest' => true,
            'fragmentUrl' => route('festival.portal.judging.index', [$account->slug, $edition]).'?fragment=1',
            ...$this->judgeListData($edition, $sheets, $assignment),
        ];

        if ($request->boolean('fragment')) {
            return response()
                ->view('festivals.shared._judge-list', $data)
                ->header('Cache-Control', 'private, no-store, max-age=0')
                ->header('Pragma', 'no-cache');
        }

        return view('festivals.portal.judging', $data);
    }

    public function editGuest(Request $request, string $accountSlug, FestivalScoreSheet $festivalScoreSheet): View
    {
        [$account, $edition, $assignment] = $this->guestSheetAssignment($request, $accountSlug, $festivalScoreSheet);

        return $this->sheetView($account, $edition, $festivalScoreSheet, $assignment, true);
    }

    public function updateGuest(FestivalScoreSheetRequest $request, string $accountSlug, FestivalScoreSheet $festivalScoreSheet, SaveFestivalScoreSheet $save): JsonResponse|RedirectResponse
    {
        [, , $assignment] = $this->guestSheetAssignment($request, $accountSlug, $festivalScoreSheet);
        $sheet = $save->execute($festivalScoreSheet, $assignment, $request->validated(), $request->user('festival'));

        return $this->scoreSaveResponse($request, $sheet, $assignment);
    }

    private function sheetView(Account $account, FestivalEdition $edition, FestivalScoreSheet $sheet, FestivalJudgeAssignment $assignment, bool $guest): View
    {
        $this->assertOwnedSheet($edition, $sheet, $assignment);
        $sheet->load(['entry.participants.portalUser', 'rubric.sections.criteria', 'scores']);
        $sheet->rubric->setRelation('sections', $this->judgingCriteria->sectionsFor($assignment, $sheet->rubric));

        return view('festivals.shared.score-sheet', [
            'account' => $account,
            'edition' => $edition,
            'sheet' => $sheet,
            'assignment' => $assignment,
            'guest' => $guest,
            'scoreProgress' => $this->judgingCriteria->scoreProgress($sheet, $assignment),
        ]);
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
    private function guestAssignment(Request $request, string $accountSlug, FestivalEdition $edition): array
    {
        $account = $request->attributes->get('festivalAccount');
        $portalUser = $request->user('festival');
        abort_unless($account instanceof Account && $account->slug === $accountSlug && $portalUser instanceof FestivalPortalUser && $portalUser->account_id === $account->id && $portalUser->role === FestivalPortalRole::Judge && $portalUser->is_active, 404);
        $this->assertEdition($account, $edition);
        $assignment = FestivalJudgeAssignment::query()->where('festival_edition_id', $edition->id)->where('festival_portal_user_id', $portalUser->id)->where('is_active', true)->firstOrFail();

        return [$account, $edition, $assignment];
    }

    /** @return array{Account, FestivalEdition, FestivalJudgeAssignment} */
    private function guestSheetAssignment(Request $request, string $accountSlug, FestivalScoreSheet $sheet): array
    {
        $sheet->loadMissing('entry.edition');
        $edition = $sheet->entry->edition;
        [$account, , $assignment] = $this->guestAssignment($request, $accountSlug, $edition);
        $this->assertOwnedSheet($edition, $sheet, $assignment);

        return [$account, $edition, $assignment];
    }

    private function scoreSaveResponse(Request $request, FestivalScoreSheet $sheet, FestivalJudgeAssignment $assignment): JsonResponse|RedirectResponse
    {
        if (! $request->expectsJson()) {
            return back()->with('status', __('app.festival_score_saved'));
        }

        return response()->json([
            'message' => __('app.festival_score_saved'),
            'total_score' => $sheet->total_score,
            'progress' => $this->judgingCriteria->scoreProgress($sheet, $assignment),
        ])->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * @param  Collection<int, FestivalScoreSheet>  $sheets
     * @return array{judgeGroups: Collection<int, array<string, mixed>>, liveScenes: Collection<int, array<string, mixed>>}
     */
    private function judgeListData(FestivalEdition $edition, Collection $sheets, FestivalJudgeAssignment $assignment): array
    {
        $timelines = $edition->timelines()
            ->with(['stage', 'edition', 'items', 'activeItem', 'lastFinishedItem'])
            ->get();
        $scenes = $this->timelinePresenter->scenes($timelines);
        $entryTimeline = [];
        $sheetsByEntry = $sheets->keyBy('festival_entry_id');
        $liveScenes = $scenes->map(function (array $scene) use (&$entryTimeline, $sheetsByEntry): array {
            $performanceItems = collect($scene['items'])
                ->where('enabled', true)
                ->where('type', 'performance')
                ->values();
            $nextPerformance = $performanceItems->firstWhere('status', 'future');

            foreach ($performanceItems as $position => $item) {
                $entryId = $item['model']->festival_entry_id;

                if (! $entryId) {
                    continue;
                }

                $status = $item['status'] === 'active'
                    ? 'active'
                    : ($nextPerformance && $item['id'] === $nextPerformance['id'] ? 'next' : $item['status']);
                $priority = ['future' => 1, 'passed' => 2, 'next' => 3, 'active' => 4][$status] ?? 0;
                $current = $entryTimeline[$entryId] ?? null;

                if (! $current || $priority > $current['priority']) {
                    $entryTimeline[$entryId] = [
                        'status' => $status,
                        'priority' => $priority,
                        'position' => sprintf('%010d:%010d', $scene['id'], $position),
                    ];
                }
            }

            return [
                'scene_name' => $scene['scene_name'],
                'state' => $scene['state'],
                'paused' => $scene['paused'],
                'current_performances' => $performanceItems
                    ->where('status', 'active')
                    ->map(fn (array $item): array => [
                        'label' => $item['label'],
                        'sheet' => $sheetsByEntry->get($item['model']->festival_entry_id),
                    ])
                    ->values(),
                'next_label' => $nextPerformance['label'] ?? null,
                'next_transition_iso' => $scene['next_transition_iso'],
            ];
        });

        $cards = $sheets->map(function (FestivalScoreSheet $sheet) use ($assignment, $entryTimeline): array {
            $timeline = $entryTimeline[$sheet->festival_entry_id] ?? ['status' => 'future', 'position' => sprintf('%010d', PHP_INT_MAX)];

            return [
                'sheet' => $sheet,
                'progress' => $this->judgingCriteria->scoreProgress($sheet, $assignment),
                'timeline_status' => $timeline['status'],
                'timeline_position' => $timeline['position'],
                'photo_participants' => $sheet->entry->participants
                    ->filter(fn ($participant): bool => filled($participant->resolvedPhotoPath()))
                    ->values(),
            ];
        });

        $judgeGroups = $cards
            ->groupBy(fn (array $card): int => $card['sheet']->entry->festival_category_id)
            ->map(function (Collection $categoryCards): array {
                $category = $categoryCards->first()['sheet']->entry->category;
                $sortedCards = $categoryCards
                    ->sortBy(fn (array $card): string => $card['timeline_position'].':'.mb_strtolower($card['sheet']->entry->entry_name))
                    ->values();

                return [
                    'category' => $category,
                    'active' => $sortedCards->contains(fn (array $card): bool => $card['timeline_status'] === 'active'),
                    'cards' => $sortedCards,
                ];
            })
            ->sortBy(fn (array $group): string => sprintf('%010d:%010d:%010d', $group['category']->direction?->sort_order ?? 0, $group['category']->sort_order, $group['category']->id))
            ->values();

        return compact('judgeGroups', 'liveScenes');
    }

    private function assertEdition(Account $account, FestivalEdition $edition): void
    {
        abort_unless($edition->account_id === $account->id, 404);
    }
}

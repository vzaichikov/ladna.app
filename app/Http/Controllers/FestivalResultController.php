<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\BuildFestivalResults;
use App\Enums\FestivalCompetitionFormat;
use App\Enums\FestivalEntryStatus;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Support\Festivals\FestivalResultTableAccess;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class FestivalResultController extends Controller
{
    public function __construct(
        private readonly FestivalWorkspaceAccess $workspaceAccess,
        private readonly FestivalResultTableAccess $tableAccess,
    ) {}

    public function index(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->viewPermissions($request, $account, $festivalEdition);
        $headAssignment = $this->tableAccess->staffAssignment($request->user(), $festivalEdition);
        $filters = [
            'q' => $request->string('q')->trim()->toString(),
        ];

        $categories = $festivalEdition->categories()
            ->with('direction')
            ->withCount([
                'entries as accepted_entries_count' => fn ($query) => $query->where('status', FestivalEntryStatus::Accepted->value),
            ])
            ->where('competition_format', FestivalCompetitionFormat::Scored->value)
            ->when($headAssignment, fn ($query) => $query->whereIn('id', $headAssignment->categories->modelKeys()))
            ->when($filters['q'] !== '', fn ($query) => $query->where('name', 'like', '%'.$filters['q'].'%'))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return view('festivals.staff.judging.results', [
            'account' => $account,
            'edition' => $festivalEdition,
            'categories' => $categories,
            'filters' => $filters,
            'hasFilters' => $filters['q'] !== '',
            'workspacePermissions' => $permissions,
        ]);
    }

    public function show(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalCategory $festivalCategory, BuildFestivalResults $results): View|Response
    {
        $permissions = $this->viewPermissions($request, $account, $festivalEdition);
        $this->assertCategory($account, $festivalEdition, $festivalCategory);
        abort_unless($this->tableAccess->categoryAllowed($this->tableAccess->staffAssignment($request->user(), $festivalEdition), $festivalCategory), 403);
        $data = [
            'account' => $account,
            'edition' => $festivalEdition,
            'category' => $festivalCategory->loadMissing('direction'),
            'results' => $results->execute($festivalEdition, $festivalCategory),
            'fragmentUrl' => route('dashboard.accounts.festivals.judging.results.show', [$account, $festivalEdition, $festivalCategory]).'?fragment=1',
            'workspacePermissions' => $permissions,
        ];

        if ($request->boolean('fragment')) {
            return response()
                ->view('festivals.staff.judging._result-list', $data)
                ->header('Cache-Control', 'private, no-store, max-age=0')
                ->header('Pragma', 'no-cache');
        }

        return view('festivals.staff.judging.result', $data);
    }

    private function assertCategory(Account $account, FestivalEdition $edition, FestivalCategory $category): void
    {
        abort_unless(
            $category->account_id === $account->id
                && $category->festival_edition_id === $edition->id
                && $category->competition_format === FestivalCompetitionFormat::Scored,
            404,
        );
    }

    /** @return array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool} */
    private function viewPermissions(Request $request, Account $account, FestivalEdition $edition): array
    {
        abort_unless($edition->account_id === $account->id, 404);
        $permissions = $this->workspaceAccess->permissions($request->user(), $account, $edition);
        abort_unless($this->tableAccess->canStaffView($request->user(), $account, $edition), 403);

        return $permissions;
    }
}

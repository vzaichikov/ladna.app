<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\BuildFestivalResultPreview;
use App\Actions\Festivals\PublishFestivalResults;
use App\Enums\FestivalEntryStatus;
use App\Http\Requests\PublishFestivalResultsRequest;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FestivalResultController extends Controller
{
    public function __construct(private readonly FestivalWorkspaceAccess $workspaceAccess) {}

    public function index(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $filters = [
            'q' => $request->string('q')->trim()->toString(),
            'publication' => in_array($request->query('publication'), ['published', 'unpublished'], true) ? $request->query('publication') : '',
        ];

        $categories = $festivalEdition->categories()
            ->withCount([
                'entries as accepted_entries_count' => fn ($query) => $query->where('status', FestivalEntryStatus::Accepted->value),
                'entries as published_results_count' => fn ($query) => $query->whereHas('result', fn ($resultQuery) => $resultQuery->whereNotNull('published_at')),
            ])
            ->when($filters['q'] !== '', fn ($query) => $query->where('name', 'like', '%'.$filters['q'].'%'))
            ->when($filters['publication'] === 'published', fn ($query) => $query->whereHas('entries.result', fn ($resultQuery) => $resultQuery->whereNotNull('published_at')))
            ->when($filters['publication'] === 'unpublished', fn ($query) => $query->whereDoesntHave('entries.result', fn ($resultQuery) => $resultQuery->whereNotNull('published_at')))
            ->paginate(20)
            ->withQueryString();

        return view('festivals.staff.judging.results', [
            'account' => $account,
            'edition' => $festivalEdition,
            'categories' => $categories,
            'filters' => $filters,
            'hasFilters' => $filters['q'] !== '' || $filters['publication'] !== '',
            'workspacePermissions' => $permissions,
        ]);
    }

    public function preview(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalCategory $festivalCategory, BuildFestivalResultPreview $preview): View
    {
        $permissions = $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertCategory($account, $festivalEdition, $festivalCategory);

        return view('festivals.staff.judging.result-preview', [
            'account' => $account,
            'edition' => $festivalEdition,
            'category' => $festivalCategory,
            'preview' => $preview->execute($festivalEdition, $festivalCategory),
            'workspacePermissions' => $permissions,
        ]);
    }

    public function publish(PublishFestivalResultsRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalCategory $festivalCategory, PublishFestivalResults $publish): RedirectResponse
    {
        $this->managerPermissions($request, $account, $festivalEdition);
        $this->assertCategory($account, $festivalEdition, $festivalCategory);
        $count = $publish->execute($festivalEdition, $festivalCategory, $request->user(), $request->validated());

        return redirect()->route('dashboard.accounts.festivals.judging.results.index', [$account, $festivalEdition])
            ->with('status', __('app.festival_results_published', ['count' => $count]));
    }

    private function assertCategory(Account $account, FestivalEdition $edition, FestivalCategory $category): void
    {
        abort_unless($category->account_id === $account->id && $category->festival_edition_id === $edition->id, 404);
    }

    /** @return array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool} */
    private function managerPermissions(Request $request, Account $account, FestivalEdition $edition): array
    {
        abort_unless($edition->account_id === $account->id, 404);
        $permissions = $this->workspaceAccess->permissions($request->user(), $account, $edition);
        abort_unless($permissions['manage'], 403);

        return $permissions;
    }
}

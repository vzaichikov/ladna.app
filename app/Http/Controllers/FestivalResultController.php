<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\PublishFestivalResults;
use App\Enums\FestivalEntryStatus;
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

    public function publish(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalCategory $festivalCategory, PublishFestivalResults $publish): RedirectResponse
    {
        $this->managerPermissions($request, $account, $festivalEdition);
        abort_unless($festivalCategory->account_id === $account->id && $festivalCategory->festival_edition_id === $festivalEdition->id, 404);
        $count = $publish->execute($festivalEdition, $festivalCategory, $request->user());

        return redirect()->route('dashboard.accounts.festivals.judging.results.index', [$account, $festivalEdition])
            ->with('status', __('app.festival_results_published', ['count' => $count]));
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

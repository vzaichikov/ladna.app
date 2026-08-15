<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\FestivalEdition;
use App\Support\Festivals\FestivalApplicationMediaReport;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FestivalApplicationMediaReportController extends Controller
{
    public function __construct(
        private readonly FestivalWorkspaceAccess $workspaceAccess,
        private readonly FestivalApplicationMediaReport $mediaReport,
    ) {}

    public function __invoke(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        abort_unless($festivalEdition->account_id === $account->id, 404);
        $permissions = $this->workspaceAccess->permissions($request->user(), $account, $festivalEdition);
        abort_unless($permissions['registrations'], 403);
        $filterData = $this->mediaReport->filterData($request, $account, $festivalEdition);

        return view('festivals.staff.application-media-report', [
            'account' => $account,
            'edition' => $festivalEdition,
            'workspacePermissions' => $permissions,
            'entries' => $this->mediaReport->paginate($account, $festivalEdition, $filterData['filters']),
            'categories' => $filterData['categories'],
            'filters' => $filterData['filters'],
            'hasFilters' => collect($filterData['filters'])->contains(fn (string $value): bool => $value !== ''),
            'hasConfiguredFields' => $this->mediaReport->hasConfiguredFields($account, $festivalEdition),
        ]);
    }
}

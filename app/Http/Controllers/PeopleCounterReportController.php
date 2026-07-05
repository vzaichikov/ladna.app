<?php

namespace App\Http\Controllers;

use App\Http\Requests\PeopleCounterReportRequest;
use App\Models\Account;
use App\Support\Reports\PeopleCounterReportData;
use Illuminate\View\View;

class PeopleCounterReportController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(PeopleCounterReportRequest $request, Account $account, PeopleCounterReportData $reportData): View
    {
        abort_unless($account->allowsRtspCameras() && $account->peopleCounterEnabled(), 404);

        $filters = $request->filters();

        return view('reports.people-counter', [
            'account' => $account,
            'filters' => $filters,
            'locations' => $account->locations()->orderBy('name')->get(['id', 'name']),
            'rooms' => $account->rooms()
                ->with('location:id,name')
                ->when($filters['location_id'] !== null, fn ($query) => $query->where('location_id', $filters['location_id']))
                ->orderBy('location_id')
                ->orderBy('name')
                ->get(['id', 'location_id', 'name']),
            'trainers' => $account->trainers()->orderBy('name')->get(['id', 'name']),
            'classes' => $reportData->forAccount($account, 25, $filters),
        ]);
    }
}

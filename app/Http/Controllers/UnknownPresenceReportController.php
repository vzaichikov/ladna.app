<?php

namespace App\Http\Controllers;

use App\Http\Requests\UnknownPresenceReportRequest;
use App\Models\Account;
use App\Support\Reports\UnknownPresenceReportData;
use Illuminate\View\View;

class UnknownPresenceReportController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(UnknownPresenceReportRequest $request, Account $account, UnknownPresenceReportData $reportData): View
    {
        abort_unless($account->allowsRtspCameras() && $account->peopleCounterEnabled(), 404);

        $filters = $request->filters();

        return view('reports.unknown-presence', [
            'account' => $account,
            'filters' => $filters,
            'locations' => $account->locations()->orderBy('name')->get(['id', 'name']),
            'rooms' => $account->rooms()
                ->with('location:id,name')
                ->when($filters['location_id'] !== null, fn ($query) => $query->where('location_id', $filters['location_id']))
                ->orderBy('location_id')
                ->orderBy('name')
                ->get(['id', 'location_id', 'name']),
            'intervals' => $reportData->forAccount($account, 25, $filters),
        ]);
    }
}

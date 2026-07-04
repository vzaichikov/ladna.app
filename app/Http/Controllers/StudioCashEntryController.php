<?php

namespace App\Http\Controllers;

use App\Actions\RecordStudioCashEntry;
use App\Http\Requests\StoreStudioCashEntryRequest;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;

class StudioCashEntryController extends Controller
{
    public function store(StoreStudioCashEntryRequest $request, Account $account, RecordStudioCashEntry $recordStudioCashEntry): RedirectResponse
    {
        $location = $account->locations()->whereKey($request->validated('location_id'))->firstOrFail();

        $recordStudioCashEntry->execute(
            $account,
            $location,
            $request->validated('direction'),
            $request->amountCents(),
            $request->occurredAt(),
            $request->user(),
            $request->validated('reason'),
        );

        return back()->with('status', __('app.studio_cash_entry_saved'));
    }
}

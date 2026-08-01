<?php

namespace App\Http\Controllers;

use App\Actions\StartFinanceEpoch;
use App\Http\Requests\StartFinanceEpochRequest;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;

class FinanceEpochController extends Controller
{
    public function __invoke(
        StartFinanceEpochRequest $request,
        Account $account,
        StartFinanceEpoch $startFinanceEpoch,
    ): RedirectResponse {
        $startFinanceEpoch->execute(
            $account,
            $request->cashboxes(),
            $request->user(),
            (string) $request->validated('reason'),
            (string) $request->validated('idempotency_key'),
        );

        return back()->with('status', __('app.finance_epoch_started'));
    }
}

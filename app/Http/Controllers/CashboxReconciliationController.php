<?php

namespace App\Http\Controllers;

use App\Actions\ReconcileCashbox;
use App\Http\Requests\ReconcileCashboxRequest;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;

class CashboxReconciliationController extends Controller
{
    public function __invoke(
        ReconcileCashboxRequest $request,
        Account $account,
        ReconcileCashbox $reconcileCashbox,
    ): RedirectResponse {
        $location = $account->locations()->whereKey($request->validated('location_id'))->firstOrFail();

        $reconcileCashbox->execute(
            $account,
            $location,
            $request->actualCountedCents(),
            $request->user(),
            (string) $request->validated('reason'),
            (string) $request->validated('idempotency_key'),
            $request->validated('currency'),
        );

        return back()->with('status', __('app.cashbox_reconciled'));
    }
}

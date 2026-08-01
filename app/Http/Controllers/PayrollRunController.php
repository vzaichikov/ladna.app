<?php

namespace App\Http\Controllers;

use App\Actions\ClosePayrollRun;
use App\Actions\VoidPayrollRun;
use App\Enums\PayrollCadence;
use App\Http\Requests\ClosePayrollRunRequest;
use App\Http\Requests\UpdatePayrollCadenceRequest;
use App\Http\Requests\VoidPayrollRunRequest;
use App\Models\Account;
use App\Models\PayrollRun;
use App\Support\Finance\PayrollPeriodResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PayrollRunController extends Controller
{
    public function index(
        Account $account,
        PayrollPeriodResolver $periodResolver,
    ): View {
        $this->authorize('manageStudioPayroll', $account);

        return view('accounts.payroll.index', [
            'account' => $account,
            'cadences' => PayrollCadence::cases(),
            'suggestedPeriod' => $periodResolver->latestCompleted($account),
            'payrollRuns' => $account->payrollRuns()
                ->with(['lines.trainer', 'supersedes', 'replacements'])
                ->orderByDesc('period_ends_on')
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
        ]);
    }

    public function updateCadence(
        UpdatePayrollCadenceRequest $request,
        Account $account,
    ): RedirectResponse {
        DB::transaction(function () use ($request, $account): void {
            $lockedAccount = Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

            $lockedAccount->update([
                'payroll_cadence' => $request->cadence(),
                'payroll_anchor_date' => $request->anchorDate(),
            ]);
        }, attempts: 5);

        return redirect()
            ->route('dashboard.accounts.payroll.index', $account)
            ->with('status', __('app.payroll_cadence_updated'));
    }

    public function store(
        ClosePayrollRunRequest $request,
        Account $account,
        ClosePayrollRun $closePayrollRun,
    ): RedirectResponse {
        $closePayrollRun->execute(
            $account,
            $request->user(),
            $request->startsOn(),
            $request->endsOn(),
            (string) $request->validated('idempotency_key'),
            $request->supersededRun(),
        );

        return redirect()
            ->route('dashboard.accounts.payroll.index', $account)
            ->with('status', __('app.payroll_run_closed'));
    }

    public function void(
        VoidPayrollRunRequest $request,
        Account $account,
        PayrollRun $payrollRun,
        VoidPayrollRun $voidPayrollRun,
    ): RedirectResponse {
        $voidPayrollRun->execute(
            $account,
            $payrollRun,
            $request->user(),
            (string) $request->validated('reason'),
        );

        return redirect()
            ->route('dashboard.accounts.payroll.index', $account)
            ->with('status', __('app.payroll_run_voided'));
    }
}

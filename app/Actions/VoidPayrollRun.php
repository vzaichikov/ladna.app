<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoidPayrollRun
{
    public function execute(Account $account, PayrollRun $payrollRun, User $actor, string $reason): PayrollRun
    {
        return DB::transaction(function () use ($account, $payrollRun, $actor, $reason): PayrollRun {
            Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $lockedRun = PayrollRun::query()
                ->whereBelongsTo($account)
                ->whereKey($payrollRun->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRun->isVoided()) {
                if ($lockedRun->void_reason === $reason) {
                    return $lockedRun;
                }

                throw ValidationException::withMessages([
                    'reason' => __('app.payroll_run_already_voided'),
                ]);
            }

            $lockedRun->forceFill([
                'status' => PayrollRun::StatusVoided,
                'voided_by_user_id' => $actor->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ])->save();

            return $lockedRun->fresh(['lines.trainer', 'replacements']);
        }, attempts: 5);
    }
}

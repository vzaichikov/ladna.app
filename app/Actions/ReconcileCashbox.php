<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\CashboxReconciliation;
use App\Models\Location;
use App\Models\StudioCashEntry;
use App\Models\User;
use App\Support\ActorSnapshot;
use App\Support\Finance\CashboxBalanceService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReconcileCashbox
{
    public function __construct(
        private readonly ActorSnapshot $actorSnapshot,
        private readonly CashboxBalanceService $cashboxBalanceService,
    ) {}

    public function execute(
        Account $account,
        Location $location,
        int $actualCountedCents,
        ?User $user,
        string $reason,
        string $idempotencyKey,
        ?string $currency = null,
        ?CarbonInterface $occurredAt = null,
    ): CashboxReconciliation {
        if ($location->account_id !== $account->id) {
            abort(404);
        }

        $currency = Str::upper($currency ?? $account->default_currency);
        $occurredAt ??= now();
        validator(
            [
                'actual_counted_cents' => $actualCountedCents,
                'currency' => $currency,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
            ],
            [
                'actual_counted_cents' => ['required', 'integer', 'min:0'],
                'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
                'reason' => ['required', 'string', 'min:3', 'max:2000'],
                'idempotency_key' => ['required', 'uuid'],
            ],
        )->validate();

        return DB::transaction(function () use ($account, $location, $actualCountedCents, $user, $reason, $idempotencyKey, $currency, $occurredAt): CashboxReconciliation {
            $existingReconciliation = CashboxReconciliation::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingReconciliation) {
                $this->ensureIdempotentMatch($existingReconciliation, $account, $location, $actualCountedCents, $currency);

                return $existingReconciliation;
            }

            $lockedLocation = Location::query()
                ->whereBelongsTo($account)
                ->whereKey($location->id)
                ->lockForUpdate()
                ->firstOrFail();
            $existingReconciliation = CashboxReconciliation::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingReconciliation) {
                $this->ensureIdempotentMatch($existingReconciliation, $account, $lockedLocation, $actualCountedCents, $currency);

                return $existingReconciliation;
            }

            $epoch = $account->activeFinanceEpoch();
            if (! $epoch) {
                throw ValidationException::withMessages([
                    'finance_epoch' => __('validation.required', ['attribute' => 'finance epoch']),
                ]);
            }

            $expectedBeforeCents = $this->cashboxBalanceService->balanceFor($account, $lockedLocation, $currency, $epoch);
            $cutoffCashEntryId = (int) StudioCashEntry::query()
                ->whereBelongsTo($account)
                ->whereBelongsTo($lockedLocation)
                ->where('currency', $currency)
                ->max('id');

            return CashboxReconciliation::query()->create([
                'account_id' => $account->id,
                'finance_epoch_id' => $epoch->id,
                'location_id' => $lockedLocation->id,
                'cutoff_cash_entry_id' => $cutoffCashEntryId > 0 ? $cutoffCashEntryId : null,
                'kind' => CashboxReconciliation::KindCount,
                'currency' => $currency,
                'expected_before_cents' => $expectedBeforeCents,
                'actual_counted_cents' => $actualCountedCents,
                'variance_cents' => $actualCountedCents - $expectedBeforeCents,
                'idempotency_key' => $idempotencyKey,
                'occurred_at' => $occurredAt,
                ...$this->actorSnapshot->capture($account, $user),
                'reason' => $reason,
            ]);
        }, attempts: 5);
    }

    private function ensureIdempotentMatch(
        CashboxReconciliation $reconciliation,
        Account $account,
        Location $location,
        int $actualCountedCents,
        string $currency,
    ): void {
        if ($reconciliation->account_id !== $account->id
            || $reconciliation->location_id !== $location->id
            || $reconciliation->actual_counted_cents !== $actualCountedCents
            || $reconciliation->currency !== $currency) {
            throw ValidationException::withMessages([
                'idempotency_key' => __('validation.unique', ['attribute' => 'idempotency key']),
            ]);
        }
    }
}

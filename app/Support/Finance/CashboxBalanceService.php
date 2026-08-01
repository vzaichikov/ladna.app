<?php

namespace App\Support\Finance;

use App\Models\Account;
use App\Models\CashboxReconciliation;
use App\Models\FinanceEpoch;
use App\Models\Location;
use App\Models\StudioCashEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CashboxBalanceService
{
    public function balanceFor(
        Account $account,
        Location $location,
        ?string $currency = null,
        ?FinanceEpoch $epoch = null,
    ): int {
        return $this->snapshotFor($account, $location, $currency, $epoch)['balance_cents'];
    }

    /**
     * @return array{
     *     epoch_id: int|null,
     *     location_id: int,
     *     currency: string,
     *     reconciliation_id: int|null,
     *     cutoff_cash_entry_id: int,
     *     base_actual_cents: int,
     *     movements_cents: int,
     *     balance_cents: int
     * }
     */
    public function snapshotFor(
        Account $account,
        Location $location,
        ?string $currency = null,
        ?FinanceEpoch $epoch = null,
    ): array {
        if ($location->account_id !== $account->id || ($epoch && $epoch->account_id !== $account->id)) {
            abort(404);
        }

        $currency = Str::upper($currency ?? $account->default_currency);
        $epoch ??= $account->activeFinanceEpoch();

        if (! $epoch) {
            return [
                'epoch_id' => null,
                'location_id' => $location->id,
                'currency' => $currency,
                'reconciliation_id' => null,
                'cutoff_cash_entry_id' => 0,
                'base_actual_cents' => 0,
                'movements_cents' => 0,
                'balance_cents' => 0,
            ];
        }

        $reconciliation = CashboxReconciliation::query()
            ->whereBelongsTo($account)
            ->whereBelongsTo($epoch, 'financeEpoch')
            ->whereBelongsTo($location)
            ->where('currency', $currency)
            ->orderByDesc('id')
            ->first();
        $cutoffCashEntryId = (int) ($reconciliation?->cutoff_cash_entry_id ?? 0);
        $baseActualCents = (int) ($reconciliation?->actual_counted_cents ?? 0);
        $movementsCents = (int) (StudioCashEntry::query()
            ->whereBelongsTo($account)
            ->whereBelongsTo($epoch, 'financeEpoch')
            ->whereBelongsTo($location)
            ->where('currency', $currency)
            ->where('id', '>', $cutoffCashEntryId)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN direction = ? THEN amount_cents WHEN direction = ? THEN -amount_cents ELSE 0 END), 0) AS net_cents',
                [StudioCashEntry::DirectionIn, StudioCashEntry::DirectionOut],
            )
            ->value('net_cents'));

        return [
            'epoch_id' => $epoch->id,
            'location_id' => $location->id,
            'currency' => $currency,
            'reconciliation_id' => $reconciliation?->id,
            'cutoff_cash_entry_id' => $cutoffCashEntryId,
            'base_actual_cents' => $baseActualCents,
            'movements_cents' => $movementsCents,
            'balance_cents' => $baseActualCents + $movementsCents,
        ];
    }

    /**
     * @return Collection<int, array{
     *     epoch_id: int|null,
     *     location_id: int,
     *     currency: string,
     *     reconciliation_id: int|null,
     *     cutoff_cash_entry_id: int,
     *     base_actual_cents: int,
     *     movements_cents: int,
     *     balance_cents: int
     * }>
     */
    public function forAccount(Account $account, ?FinanceEpoch $epoch = null): Collection
    {
        $epoch ??= $account->activeFinanceEpoch();
        $currencies = collect([$account->default_currency]);

        if ($epoch) {
            $currencies = $currencies
                ->merge(
                    StudioCashEntry::query()
                        ->whereBelongsTo($account)
                        ->whereBelongsTo($epoch, 'financeEpoch')
                        ->distinct()
                        ->pluck('currency'),
                )
                ->merge(
                    CashboxReconciliation::query()
                        ->whereBelongsTo($account)
                        ->whereBelongsTo($epoch, 'financeEpoch')
                        ->distinct()
                        ->pluck('currency'),
                );
        }

        $currencies = $currencies
            ->filter(fn (mixed $currency): bool => is_string($currency) && $currency !== '')
            ->map(fn (string $currency): string => Str::upper($currency))
            ->unique()
            ->values();

        return $account->locations()
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->flatMap(fn (Location $location): Collection => $currencies->map(
                fn (string $currency): array => $this->snapshotFor($account, $location, $currency, $epoch),
            ))
            ->values();
    }
}

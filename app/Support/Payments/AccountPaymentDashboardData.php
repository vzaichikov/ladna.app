<?php

namespace App\Support\Payments;

use App\Enums\CustomerPurchaseStatus;
use App\Enums\FiscalReceiptStatus;
use App\Models\Account;
use App\Models\CustomerPurchase;
use App\Models\ExpenseCategory;
use App\Models\FiscalReceipt;
use App\Models\StudioCashEntry;
use App\Models\StudioExpense;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AccountPaymentDashboardData
{
    /**
     * @param  array{date_from: string, date_to: string, search: string|null, payment_method: string|null, status: string|null, provider: string|null, location_id: int|null, expense_category_id: int|null, expense_payment_method: string|null, expense_status: string|null}  $filters
     * @return array<string, mixed>
     */
    public function build(
        Account $account,
        array $filters,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        bool $fiscalizationEnabled,
    ): array {
        $periodPaymentQuery = $this->periodPaymentQuery($account, $startsAt, $endsAt);
        $paymentQuery = $this->paymentQuery(clone $periodPaymentQuery, $filters);
        $cashBalances = $this->cashBalances($account);
        $expenseQuery = $this->expenseQuery($account, $filters, $startsAt, $endsAt);

        return [
            'payments' => (clone $paymentQuery)
                ->with([
                    'customer',
                    'location',
                    'classPassPlan',
                    'customerClassPass',
                    'classBooking.scheduledClass.location',
                    'classBooking.scheduledClass.room',
                    'fiscalReceipt',
                    'fiscalReceipts',
                    'corrections.previousLocation',
                    'corrections.newLocation',
                ])
                ->effectiveNewestFirst()
                ->paginate(20, ['*'], 'payments_page')
                ->withQueryString(),
            'expenses' => (clone $expenseQuery)
                ->with(['category', 'location', 'cashEntries'])
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->paginate(20, ['*'], 'expenses_page')
                ->withQueryString(),
            'cashEntries' => $account->studioCashEntries()
                ->with(['location', 'expense.category'])
                ->whereBetween('occurred_at', [$startsAt, $endsAt])
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->take(20)
                ->get(),
            'cashBalances' => $cashBalances,
            'stats' => $this->paymentStats($paymentQuery, $periodPaymentQuery, $cashBalances, $fiscalizationEnabled),
            'periodOverview' => $this->periodOverview($account, $startsAt, $endsAt),
            'expenseCategoryBreakdown' => $this->expenseCategoryBreakdown($account, $filters, $startsAt, $endsAt),
            'expenseCategories' => $account->expenseCategories()->ordered()->get(),
            'activeExpenseCategories' => $account->expenseCategories()->active()->ordered()->get(),
            'providers' => $this->providerOptions($account),
        ];
    }

    /**
     * @param  array{date_from: string, date_to: string, search: string|null, payment_method: string|null, status: string|null, provider: string|null, location_id: int|null, expense_category_id: int|null, expense_payment_method: string|null, expense_status: string|null}  $filters
     */
    private function periodPaymentQuery(Account $account, CarbonInterface $startsAt, CarbonInterface $endsAt): Builder
    {
        return CustomerPurchase::query()
            ->whereBelongsTo($account)
            ->withinEffectiveDateRange($startsAt, $endsAt);
    }

    /**
     * @param  Builder<CustomerPurchase>  $query
     * @param  array{date_from: string, date_to: string, search: string|null, payment_method: string|null, status: string|null, provider: string|null, location_id: int|null, expense_category_id: int|null, expense_payment_method: string|null, expense_status: string|null}  $filters
     */
    private function paymentQuery(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'], function (Builder $query, string $search): void {
                $query->whereHas('customer', function (Builder $query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($filters['payment_method'] === CustomerPurchase::PaymentMethodCash, fn (Builder $query): Builder => $query->whereIn('payment_source', [
                CustomerPurchase::SourceManualCashClassPass,
                CustomerPurchase::SourceManualCashBooking,
            ]))
            ->when($filters['payment_method'] === CustomerPurchase::PaymentMethodOnline, fn (Builder $query): Builder => $query->where('payment_source', CustomerPurchase::SourceOnlineCheckout))
            ->when($filters['status'], fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($filters['provider'], fn (Builder $query, string $provider): Builder => $query->where('provider', $provider))
            ->when($filters['location_id'], fn (Builder $query, int $locationId): Builder => $query->where('location_id', $locationId));
    }

    /**
     * @param  array{date_from: string, date_to: string, search: string|null, payment_method: string|null, status: string|null, provider: string|null, location_id: int|null, expense_category_id: int|null, expense_payment_method: string|null, expense_status: string|null}  $filters
     */
    private function expenseQuery(Account $account, array $filters, CarbonInterface $startsAt, CarbonInterface $endsAt): Builder
    {
        return StudioExpense::query()
            ->whereBelongsTo($account)
            ->whereBetween('occurred_at', [$startsAt, $endsAt])
            ->when($filters['expense_category_id'], fn (Builder $query, int $categoryId): Builder => $query->where('expense_category_id', $categoryId))
            ->when($filters['expense_payment_method'], fn (Builder $query, string $paymentMethod): Builder => $query->where('payment_method', $paymentMethod))
            ->when($filters['expense_status'] === StudioExpense::StatusActive, fn (Builder $query): Builder => $query->active())
            ->when($filters['expense_status'] === StudioExpense::StatusVoided, fn (Builder $query): Builder => $query->voided());
    }

    /**
     * @param  Builder<CustomerPurchase>  $paymentQuery
     * @param  Builder<CustomerPurchase>  $periodPaymentQuery
     * @param  Collection<int, array{location: mixed, manual_cash_by_currency: array<string, int>, cash_in_by_currency: array<string, int>, cash_out_by_currency: array<string, int>, balance_by_currency: array<string, int>}>  $cashBalances
     * @return array{total: int, paid_amounts_by_currency: array<string, int>, pending: int, failed: int, fiscal_failed: int, cash_balance_by_currency: array<string, int>}
     */
    private function paymentStats(
        Builder $paymentQuery,
        Builder $periodPaymentQuery,
        Collection $cashBalances,
        bool $fiscalizationEnabled,
    ): array {
        $fiscalFailures = $fiscalizationEnabled
            ? FiscalReceipt::query()
                ->where('status', FiscalReceiptStatus::Failed->value)
                ->where('payment_type', (new CustomerPurchase)->getMorphClass())
                ->whereIn('payment_id', (clone $periodPaymentQuery)->select('id'))
                ->count()
            : 0;

        return [
            'total' => (clone $paymentQuery)->count(),
            'paid_amounts_by_currency' => $this->totalsByCurrency(
                (clone $paymentQuery)->where('status', CustomerPurchaseStatus::PaymentPaid->value),
            ),
            'pending' => (clone $periodPaymentQuery)
                ->whereIn('status', [
                    CustomerPurchaseStatus::PaymentStarted->value,
                    CustomerPurchaseStatus::PaymentPending->value,
                ])
                ->count(),
            'failed' => (clone $periodPaymentQuery)
                ->whereIn('status', [
                    CustomerPurchaseStatus::PaymentFailed->value,
                    CustomerPurchaseStatus::PaymentCancelled->value,
                    CustomerPurchaseStatus::PaymentExpired->value,
                ])
                ->count(),
            'fiscal_failed' => $fiscalFailures,
            'cash_balance_by_currency' => $this->mergeCurrencyTotals(...$cashBalances->pluck('balance_by_currency')->all()),
        ];
    }

    /**
     * @return array{income_by_currency: array<string, int>, expense_by_currency: array<string, int>, remaining_by_currency: array<string, int>, cash_received_by_currency: array<string, int>, collection_by_currency: array<string, int>}
     */
    private function periodOverview(Account $account, CarbonInterface $startsAt, CarbonInterface $endsAt): array
    {
        $incomeByCurrency = $this->totalsByCurrency(CustomerPurchase::query()
            ->whereBelongsTo($account)
            ->withinEffectiveDateRange($startsAt, $endsAt)
            ->where('status', CustomerPurchaseStatus::PaymentPaid->value));
        $expenseByCurrency = $this->totalsByCurrency(StudioExpense::query()
            ->whereBelongsTo($account)
            ->active()
            ->whereBetween('occurred_at', [$startsAt, $endsAt]));
        $cashReceivedByCurrency = $this->totalsByCurrency(CustomerPurchase::query()
            ->whereBelongsTo($account)
            ->withinEffectiveDateRange($startsAt, $endsAt)
            ->where('status', CustomerPurchaseStatus::PaymentPaid->value)
            ->whereIn('payment_source', [
                CustomerPurchase::SourceManualCashClassPass,
                CustomerPurchase::SourceManualCashBooking,
            ]));
        $collectionByCurrency = $this->totalsByCurrency(StudioCashEntry::query()
            ->whereBelongsTo($account)
            ->where('purpose', StudioCashEntry::PurposeOwnerWithdrawal)
            ->whereBetween('occurred_at', [$startsAt, $endsAt]));

        return [
            'income_by_currency' => $incomeByCurrency,
            'expense_by_currency' => $expenseByCurrency,
            'remaining_by_currency' => $this->subtractCurrencyTotals(
                $this->subtractCurrencyTotals($incomeByCurrency, $expenseByCurrency),
                $collectionByCurrency,
            ),
            'cash_received_by_currency' => $cashReceivedByCurrency,
            'collection_by_currency' => $collectionByCurrency,
        ];
    }

    /**
     * @param  array{date_from: string, date_to: string, search: string|null, payment_method: string|null, status: string|null, provider: string|null, location_id: int|null, expense_category_id: int|null, expense_payment_method: string|null, expense_status: string|null}  $filters
     * @return Collection<int, array{category: ExpenseCategory, currency: string, amount_cents: int, share: float}>
     */
    private function expenseCategoryBreakdown(Account $account, array $filters, CarbonInterface $startsAt, CarbonInterface $endsAt): Collection
    {
        $totals = StudioExpense::query()
            ->whereBelongsTo($account)
            ->active()
            ->whereBetween('occurred_at', [$startsAt, $endsAt])
            ->when($filters['expense_category_id'], fn (Builder $query, int $categoryId): Builder => $query->where('expense_category_id', $categoryId))
            ->when($filters['expense_payment_method'], fn (Builder $query, string $paymentMethod): Builder => $query->where('payment_method', $paymentMethod))
            ->selectRaw('expense_category_id, currency, SUM(amount_cents) as amount_cents')
            ->groupBy('expense_category_id', 'currency')
            ->get();
        $grandTotalsByCurrency = $totals
            ->groupBy('currency')
            ->map(fn (Collection $currencyTotals): int => (int) $currencyTotals->sum('amount_cents'));

        if ($totals->isEmpty()) {
            return collect();
        }

        $categories = ExpenseCategory::query()
            ->whereBelongsTo($account)
            ->whereKey($totals->pluck('expense_category_id')->unique())
            ->get()
            ->keyBy('id');

        return $totals
            ->map(function (StudioExpense $total) use ($categories, $grandTotalsByCurrency): array {
                $currency = (string) $total->currency;
                $amountCents = (int) $total->amount_cents;

                return [
                    'category' => $categories->get($total->expense_category_id),
                    'currency' => $currency,
                    'amount_cents' => $amountCents,
                    'share' => $amountCents / (int) $grandTotalsByCurrency[$currency] * 100,
                ];
            })
            ->sortByDesc('amount_cents')
            ->values();
    }

    /**
     * @return array<string, string>
     */
    private function providerOptions(Account $account): array
    {
        return $account->customerPurchases()
            ->select('provider')
            ->distinct()
            ->orderBy('provider')
            ->pluck('provider')
            ->mapWithKeys(fn (string $provider): array => [
                $provider => $this->providerLabel($provider),
            ])
            ->all();
    }

    private function providerLabel(string $provider): string
    {
        $translationKey = 'app.provider_'.$provider;
        $label = __($translationKey);

        return $label === $translationKey
            ? config('integrations.providers.'.$provider.'.label', $provider)
            : $label;
    }

    /**
     * @return Collection<int, array{location: mixed, manual_cash_by_currency: array<string, int>, cash_in_by_currency: array<string, int>, cash_out_by_currency: array<string, int>, balance_by_currency: array<string, int>}>
     */
    private function cashBalances(Account $account): Collection
    {
        $locations = $account->locations()
            ->orderBy('name')
            ->get();
        $locationIds = $locations->pluck('id');
        $manualCashByLocation = $account->customerPurchases()
            ->whereIn('location_id', $locationIds)
            ->where('status', CustomerPurchaseStatus::PaymentPaid->value)
            ->whereIn('payment_source', [
                CustomerPurchase::SourceManualCashClassPass,
                CustomerPurchase::SourceManualCashBooking,
            ])
            ->selectRaw('location_id, currency, SUM(amount_cents) as amount_cents')
            ->groupBy('location_id', 'currency')
            ->get()
            ->groupBy('location_id');
        $cashEntriesByLocation = $account->studioCashEntries()
            ->whereIn('location_id', $locationIds)
            ->selectRaw('location_id, direction, currency, SUM(amount_cents) as amount_cents')
            ->groupBy('location_id', 'direction', 'currency')
            ->get()
            ->groupBy('location_id');

        return $locations->map(function ($location) use ($manualCashByLocation, $cashEntriesByLocation): array {
            $entries = $cashEntriesByLocation->get($location->id, collect());
            $manualCashByCurrency = $this->currencyTotalsFromRows($manualCashByLocation->get($location->id, collect()));
            $cashInByCurrency = $this->currencyTotalsFromRows($entries->where('direction', StudioCashEntry::DirectionIn));
            $cashOutByCurrency = $this->currencyTotalsFromRows($entries->where('direction', StudioCashEntry::DirectionOut));

            return [
                'location' => $location,
                'manual_cash_by_currency' => $manualCashByCurrency,
                'cash_in_by_currency' => $cashInByCurrency,
                'cash_out_by_currency' => $cashOutByCurrency,
                'balance_by_currency' => $this->subtractCurrencyTotals(
                    $this->mergeCurrencyTotals($manualCashByCurrency, $cashInByCurrency),
                    $cashOutByCurrency,
                ),
            ];
        });
    }

    /**
     * @return array<string, int>
     */
    private function totalsByCurrency(Builder $query): array
    {
        return $query
            ->selectRaw('currency, SUM(amount_cents) as amount_cents')
            ->groupBy('currency')
            ->pluck('amount_cents', 'currency')
            ->map(fn (mixed $amountCents): int => (int) $amountCents)
            ->sortKeys()
            ->all();
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return array<string, int>
     */
    private function currencyTotalsFromRows(Collection $rows): array
    {
        return $rows
            ->mapWithKeys(fn (mixed $row): array => [(string) $row->currency => (int) $row->amount_cents])
            ->sortKeys()
            ->all();
    }

    /**
     * @param  array<string, int>  ...$totals
     * @return array<string, int>
     */
    private function mergeCurrencyTotals(array ...$totals): array
    {
        $merged = [];

        foreach ($totals as $currencyTotals) {
            foreach ($currencyTotals as $currency => $amountCents) {
                $merged[$currency] = ($merged[$currency] ?? 0) + $amountCents;
            }
        }

        ksort($merged);

        return $merged;
    }

    /**
     * @param  array<string, int>  $minuend
     * @param  array<string, int>  $subtrahend
     * @return array<string, int>
     */
    private function subtractCurrencyTotals(array $minuend, array $subtrahend): array
    {
        $result = $minuend;

        foreach ($subtrahend as $currency => $amountCents) {
            $result[$currency] = ($result[$currency] ?? 0) - $amountCents;
        }

        ksort($result);

        return $result;
    }
}

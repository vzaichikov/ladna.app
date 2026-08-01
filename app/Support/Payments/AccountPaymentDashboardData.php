<?php

namespace App\Support\Payments;

use App\Enums\CustomerPurchaseStatus;
use App\Enums\EventOrderStatus;
use App\Enums\FiscalReceiptStatus;
use App\Models\Account;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseRefund;
use App\Models\EventOrder;
use App\Models\ExpenseCategory;
use App\Models\FiscalReceipt;
use App\Models\StudioCashEntry;
use App\Models\StudioExpense;
use App\Support\Finance\CashboxBalanceService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AccountPaymentDashboardData
{
    public function __construct(
        private readonly AccountPaymentHistory $paymentHistory,
        private readonly CashboxBalanceService $cashboxBalanceService,
    ) {}

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
        $activeEpoch = $account->activeFinanceEpoch();
        if ($activeEpoch && $activeEpoch->starts_at->greaterThan($startsAt)) {
            $startsAt = $activeEpoch->starts_at;
        }

        $periodPaymentQuery = $this->periodPaymentQuery($account, $startsAt, $endsAt);
        $paymentQuery = $this->paymentQuery(clone $periodPaymentQuery, $filters);
        $periodRefundQuery = CustomerPurchaseRefund::query()
            ->whereBelongsTo($account)
            ->whereBetween('refunded_at', [$startsAt, $endsAt]);
        $refundQuery = $this->paymentHistory->refundQuery($account, $filters, $startsAt, $endsAt);
        $periodEventPaymentQuery = $this->periodEventPaymentQuery($account, $startsAt, $endsAt);
        $eventPaymentQuery = $this->eventPaymentQuery(clone $periodEventPaymentQuery, $filters);
        $cashBalances = $this->cashBalances($account);
        $expenseQuery = $this->expenseQuery($account, $filters, $startsAt, $endsAt);

        return [
            'payments' => $this->paymentHistory->paginate($account, $filters, $startsAt, $endsAt),
            'expenses' => (clone $expenseQuery)
                ->with(['category', 'location', 'cashEntries'])
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->paginate(20, ['*'], 'expenses_page')
                ->withQueryString(),
            'eventPayments' => (clone $eventPaymentQuery)
                ->with(['event', 'fiscalReceipt'])
                ->orderByRaw('COALESCE(paid_at, created_at) DESC')
                ->paginate(20, ['*'], 'event_payments_page')
                ->withQueryString(),
            'cashEntries' => $account->studioCashEntries()
                ->with(['location', 'expense.category', 'customerPurchaseRefund.customerPurchase.customer'])
                ->when($activeEpoch, fn (Builder $query) => $query->whereBelongsTo($activeEpoch, 'financeEpoch'))
                ->whereBetween('occurred_at', [$startsAt, $endsAt])
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->take(20)
                ->get(),
            'cashBalances' => $cashBalances,
            'stats' => $this->paymentStats($paymentQuery, $periodPaymentQuery, $refundQuery, $periodRefundQuery, $eventPaymentQuery, $periodEventPaymentQuery, $cashBalances, $fiscalizationEnabled),
            'periodOverview' => $this->periodOverview($account, $startsAt, $endsAt),
            'expenseCategoryBreakdown' => $this->expenseCategoryBreakdown($account, $filters, $startsAt, $endsAt),
            'expenseCategories' => $account->expenseCategories()->ordered()->get(),
            'activeExpenseCategories' => $account->expenseCategories()->active()->ordered()->get(),
            'providers' => $this->providerOptions($account),
        ];
    }

    private function periodEventPaymentQuery(Account $account, CarbonInterface $startsAt, CarbonInterface $endsAt): Builder
    {
        return EventOrder::query()
            ->whereBelongsTo($account)
            ->where(fn (Builder $query) => $query
                ->whereBetween('paid_at', [$startsAt, $endsAt])
                ->orWhere(fn (Builder $query) => $query->whereNull('paid_at')->whereBetween('created_at', [$startsAt, $endsAt])));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function eventPaymentQuery(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'], fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                ->where('buyer_name', 'like', "%{$search}%")
                ->orWhere('buyer_email', 'like', "%{$search}%")
                ->orWhere('order_id', 'like', "%{$search}%")))
            ->when($filters['payment_method'] === CustomerPurchase::PaymentMethodCash, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($filters['status'], fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['provider'], fn (Builder $query, string $provider) => $query->where('provider', $provider));
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
     * @param  Builder<CustomerPurchaseRefund>  $refundQuery
     * @param  Builder<CustomerPurchaseRefund>  $periodRefundQuery
     * @param  Builder<EventOrder>  $eventPaymentQuery
     * @param  Builder<EventOrder>  $periodEventPaymentQuery
     * @return array{total: int, paid_amounts_by_currency: array<string, int>, refund_amounts_by_currency: array<string, int>, pending: int, failed: int, fiscal_failed: int, cash_balance_by_currency: array<string, int>}
     */
    private function paymentStats(
        Builder $paymentQuery,
        Builder $periodPaymentQuery,
        Builder $refundQuery,
        Builder $periodRefundQuery,
        Builder $eventPaymentQuery,
        Builder $periodEventPaymentQuery,
        Collection $cashBalances,
        bool $fiscalizationEnabled,
    ): array {
        $fiscalFailures = $fiscalizationEnabled
            ? FiscalReceipt::query()
                ->where('status', FiscalReceiptStatus::Failed->value)
                ->where('payment_type', (new CustomerPurchase)->getMorphClass())
                ->whereIn('payment_id', (clone $periodPaymentQuery)->select('id'))
                ->count() + FiscalReceipt::query()
                ->where('status', FiscalReceiptStatus::Failed->value)
                ->where('payment_type', (new EventOrder)->getMorphClass())
                ->whereIn('payment_id', (clone $periodEventPaymentQuery)->select('id'))
                ->count()
                + FiscalReceipt::query()
                    ->where('status', FiscalReceiptStatus::Failed->value)
                    ->where('payment_type', (new CustomerPurchaseRefund)->getMorphClass())
                    ->whereIn('payment_id', (clone $periodRefundQuery)->select('id'))
                    ->count()
            : 0;

        $grossPaidAmounts = $this->mergeCurrencyTotals(
            $this->totalsByCurrency((clone $paymentQuery)->where('status', CustomerPurchaseStatus::PaymentPaid->value)),
            $this->totalsByCurrency((clone $eventPaymentQuery)->whereIn('status', [
                EventOrderStatus::Paid->value,
                EventOrderStatus::RefundRequired->value,
                EventOrderStatus::PaidRequiresRefund->value,
            ])),
        );
        $refundAmounts = $this->totalsByCurrency(clone $refundQuery);

        return [
            'total' => (clone $paymentQuery)->count() + (clone $refundQuery)->count() + (clone $eventPaymentQuery)->count(),
            'paid_amounts_by_currency' => $this->subtractCurrencyTotals($grossPaidAmounts, $refundAmounts),
            'refund_amounts_by_currency' => $refundAmounts,
            'pending' => (clone $periodPaymentQuery)
                ->whereIn('status', [
                    CustomerPurchaseStatus::PaymentStarted->value,
                    CustomerPurchaseStatus::PaymentPending->value,
                ])
                ->count() + (clone $periodEventPaymentQuery)->where('status', EventOrderStatus::Pending->value)->count(),
            'failed' => (clone $periodPaymentQuery)
                ->whereIn('status', [
                    CustomerPurchaseStatus::PaymentFailed->value,
                    CustomerPurchaseStatus::PaymentCancelled->value,
                    CustomerPurchaseStatus::PaymentExpired->value,
                ])
                ->count() + (clone $periodEventPaymentQuery)->whereIn('status', [
                    EventOrderStatus::Failed->value,
                    EventOrderStatus::Cancelled->value,
                    EventOrderStatus::Expired->value,
                    EventOrderStatus::PaidRequiresRefund->value,
                ])->count(),
            'fiscal_failed' => $fiscalFailures,
            'cash_balance_by_currency' => $this->mergeCurrencyTotals(...$cashBalances->pluck('balance_by_currency')->all()),
        ];
    }

    /**
     * @return array{gross_income_by_currency: array<string, int>, refunds_by_currency: array<string, int>, income_by_currency: array<string, int>, expense_by_currency: array<string, int>, remaining_by_currency: array<string, int>, cash_received_by_currency: array<string, int>, collection_by_currency: array<string, int>}
     */
    private function periodOverview(Account $account, CarbonInterface $startsAt, CarbonInterface $endsAt): array
    {
        $grossIncomeByCurrency = $this->totalsByCurrency(CustomerPurchase::query()
            ->whereBelongsTo($account)
            ->withinEffectiveDateRange($startsAt, $endsAt)
            ->where('status', CustomerPurchaseStatus::PaymentPaid->value));
        $grossIncomeByCurrency = $this->mergeCurrencyTotals(
            $grossIncomeByCurrency,
            $this->totalsByCurrency(EventOrder::query()
                ->whereBelongsTo($account)
                ->whereBetween('paid_at', [$startsAt, $endsAt])
                ->whereIn('status', [
                    EventOrderStatus::Paid->value,
                    EventOrderStatus::RefundRequired->value,
                    EventOrderStatus::PaidRequiresRefund->value,
                ])),
        );
        $refundsByCurrency = $this->totalsByCurrency(CustomerPurchaseRefund::query()
            ->whereBelongsTo($account)
            ->whereBetween('refunded_at', [$startsAt, $endsAt]));
        $incomeByCurrency = $this->subtractCurrencyTotals($grossIncomeByCurrency, $refundsByCurrency);
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
            'gross_income_by_currency' => $grossIncomeByCurrency,
            'refunds_by_currency' => $refundsByCurrency,
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
        $customerProviders = $account->customerPurchases()
            ->select('provider')
            ->distinct()
            ->orderBy('provider')
            ->pluck('provider')
            ->mapWithKeys(fn (string $provider): array => [
                $provider => $this->providerLabel($provider),
            ])
            ->all();
        $eventProviders = $account->eventOrders()->whereNotNull('provider')->distinct()->pluck('provider')
            ->mapWithKeys(fn (string $provider): array => [$provider => $this->providerLabel($provider)])
            ->all();

        return collect($customerProviders)->merge($eventProviders)->sortKeys()->all();
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
     * @return Collection<int, array{location: mixed, base_actual_by_currency: array<string, int>, reconciled_by_currency: array<string, bool>, cash_in_by_currency: array<string, int>, cash_out_by_currency: array<string, int>, balance_by_currency: array<string, int>}>
     */
    private function cashBalances(Account $account): Collection
    {
        $locations = $account->locations()
            ->orderBy('name')
            ->get();
        $epoch = $account->activeFinanceEpoch();
        $snapshots = $this->cashboxBalanceService->forAccount($account, $epoch)->groupBy('location_id');

        return $locations->map(function ($location) use ($account, $epoch, $snapshots): array {
            $locationSnapshots = $snapshots->get($location->id, collect());
            $baseActualByCurrency = [];
            $reconciledByCurrency = [];
            $cashInByCurrency = [];
            $cashOutByCurrency = [];
            $balanceByCurrency = [];

            foreach ($locationSnapshots as $snapshot) {
                $currency = $snapshot['currency'];
                $baseActualByCurrency[$currency] = $snapshot['base_actual_cents'];
                $reconciledByCurrency[$currency] = $snapshot['reconciliation_id'] !== null;
                $balanceByCurrency[$currency] = $snapshot['balance_cents'];
                $entryQuery = StudioCashEntry::query()
                    ->whereBelongsTo($account)
                    ->when($epoch, fn (Builder $query) => $query->whereBelongsTo($epoch, 'financeEpoch'))
                    ->whereBelongsTo($location)
                    ->where('currency', $currency)
                    ->where('id', '>', $snapshot['cutoff_cash_entry_id']);
                $cashInByCurrency[$currency] = (int) (clone $entryQuery)
                    ->where('direction', StudioCashEntry::DirectionIn)
                    ->sum('amount_cents');
                $cashOutByCurrency[$currency] = (int) (clone $entryQuery)
                    ->where('direction', StudioCashEntry::DirectionOut)
                    ->sum('amount_cents');
            }

            return [
                'location' => $location,
                'base_actual_by_currency' => $baseActualByCurrency,
                'reconciled_by_currency' => $reconciledByCurrency,
                'cash_in_by_currency' => $cashInByCurrency,
                'cash_out_by_currency' => $cashOutByCurrency,
                'balance_by_currency' => $balanceByCurrency,
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

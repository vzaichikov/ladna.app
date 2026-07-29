<?php

namespace App\Support\Payments;

use App\Enums\CustomerPurchaseStatus;
use App\Enums\EventOrderStatus;
use App\Enums\FiscalReceiptStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerPurchase;
use App\Models\EventOrder;
use App\Models\FiscalReceipt;
use App\Models\Location;
use App\Models\StudioCashEntry;
use App\Models\StudioExpense;
use App\Support\DateTimePresenter;
use App\Support\MaskedContactPresenter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudioPaymentToolData
{
    private const MaximumPeriodDays = 366;

    private const DefaultSearchLimit = 20;

    private const MaximumSearchLimit = 50;

    public function __construct(private readonly MaskedContactPresenter $maskedContact) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function overview(Account $account, array $arguments): array
    {
        [$fromDate, $toDate, $startsAt, $endsAt] = $this->period($account, $arguments);
        $location = $this->location($account, $arguments['location_id'] ?? null);
        $locationId = $location?->id;

        $customerPayments = $this->customerPayments($account, $startsAt, $endsAt, $locationId);
        $eventPayments = $this->eventPayments($account, $startsAt, $endsAt, $locationId);
        $expenses = $this->expenses($account, $startsAt, $endsAt, $locationId);
        $ownerWithdrawals = StudioCashEntry::query()
            ->whereBelongsTo($account)
            ->where('purpose', StudioCashEntry::PurposeOwnerWithdrawal)
            ->whereBetween('occurred_at', [$startsAt, $endsAt])
            ->when($locationId, fn (Builder $query, int $id): Builder => $query->where('location_id', $id));
        $cashBalances = $this->cashBalances($account, $locationId);

        $customerIncome = $this->totalsByCurrency(
            (clone $customerPayments)->where('status', CustomerPurchaseStatus::PaymentPaid->value),
        );
        $eventIncome = $this->totalsByCurrency(
            (clone $eventPayments)->whereIn('status', [
                EventOrderStatus::Paid->value,
                EventOrderStatus::RefundRequired->value,
                EventOrderStatus::PaidRequiresRefund->value,
            ]),
        );
        $income = $this->mergeCurrencyTotals($customerIncome, $eventIncome);
        $expenseTotals = $this->totalsByCurrency((clone $expenses)->active());
        $withdrawalTotals = $this->totalsByCurrency(clone $ownerWithdrawals);
        $cashReceived = $this->totalsByCurrency(
            (clone $customerPayments)
                ->where('status', CustomerPurchaseStatus::PaymentPaid->value)
                ->whereIn('payment_source', [
                    CustomerPurchase::SourceManualCashClassPass,
                    CustomerPurchase::SourceManualCashBooking,
                ]),
        );
        $cashBalanceTotals = $this->mergeCurrencyTotals(
            ...$cashBalances->pluck('balance_by_currency')->all(),
        );

        $outstandingPasses = CustomerClassPass::query()
            ->whereBelongsTo($account)
            ->outstandingBalance()
            ->when($locationId, fn (Builder $query, int $id): Builder => $query->where('issued_location_id', $id));

        return [
            'status' => 'ok',
            'timezone' => DateTimePresenter::accountTimezone($account),
            'period' => [
                'date_from' => $fromDate,
                'date_to' => $toDate,
            ],
            'location' => $location ? [
                'location_id' => $location->id,
                'name' => $location->name,
            ] : null,
            'totals' => [
                'customer_income' => $this->moneyTotals($customerIncome),
                'event_income' => $this->moneyTotals($eventIncome),
                'income' => $this->moneyTotals($income),
                'operational_expenses' => $this->moneyTotals($expenseTotals),
                'owner_withdrawals' => $this->moneyTotals($withdrawalTotals),
                'remaining' => $this->moneyTotals(
                    $this->subtractCurrencyTotals(
                        $this->subtractCurrencyTotals($income, $expenseTotals),
                        $withdrawalTotals,
                    ),
                ),
                'cash_received' => $this->moneyTotals($cashReceived),
                'cash_balance' => $this->moneyTotals($cashBalanceTotals),
            ],
            'counts' => [
                'customer_payments_by_status' => $this->countsByStatus(clone $customerPayments),
                'event_payments_by_status' => $this->countsByStatus(clone $eventPayments),
                'operational_expenses' => [
                    StudioExpense::StatusActive => (clone $expenses)->active()->count(),
                    StudioExpense::StatusVoided => (clone $expenses)->voided()->count(),
                ],
                'refund_required' => (clone $eventPayments)
                    ->whereIn('status', [
                        EventOrderStatus::RefundRequired->value,
                        EventOrderStatus::PaidRequiresRefund->value,
                    ])
                    ->count(),
                'fiscal_failed' => $this->fiscalFailureCount($customerPayments, $eventPayments),
                'outstanding_class_passes' => [
                    'unpaid' => (clone $outstandingPasses)->unpaid()->count(),
                    'partial' => (clone $outstandingPasses)->partiallyPaid()->count(),
                ],
            ],
            'cash_balances' => $cashBalances
                ->map(fn (array $balance): array => [
                    'location_id' => $balance['location']->id,
                    'location_name' => $balance['location']->name,
                    'manual_cash' => $this->moneyTotals($balance['manual_cash_by_currency']),
                    'cash_in' => $this->moneyTotals($balance['cash_in_by_currency']),
                    'cash_out' => $this->moneyTotals($balance['cash_out_by_currency']),
                    'balance' => $this->moneyTotals($balance['balance_by_currency']),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function search(Account $account, array $arguments): array
    {
        [$fromDate, $toDate, $startsAt, $endsAt] = $this->period($account, $arguments);
        $location = $this->location($account, $arguments['location_id'] ?? null);
        $locationId = $location?->id;
        $kind = filled($arguments['kind'] ?? null) ? (string) $arguments['kind'] : null;
        $status = filled($arguments['status'] ?? null) ? (string) $arguments['status'] : null;
        $query = Str::of((string) ($arguments['query'] ?? ''))->squish()->toString();
        $limit = min(max((int) ($arguments['limit'] ?? self::DefaultSearchLimit), 1), self::MaximumSearchLimit);
        $rows = collect();

        if ($kind === null || $kind === 'customer_payment') {
            $rows = $rows->concat($this->customerPaymentRows(
                $account,
                $startsAt,
                $endsAt,
                $locationId,
                $query,
                $status,
                $limit,
            ));
        }

        if ($kind === null || $kind === 'event_payment') {
            $rows = $rows->concat($this->eventPaymentRows(
                $account,
                $startsAt,
                $endsAt,
                $locationId,
                $query,
                $status,
                $limit,
            ));
        }

        if ($kind === null || $kind === 'operational_expense') {
            $rows = $rows->concat($this->expenseRows(
                $account,
                $startsAt,
                $endsAt,
                $locationId,
                $query,
                $status,
                $limit,
            ));
        }

        if (($kind === null || $kind === 'cash_movement') && $status === null) {
            $rows = $rows->concat($this->cashMovementRows(
                $account,
                $startsAt,
                $endsAt,
                $locationId,
                $query,
                $limit,
            ));
        }

        $sorted = $rows->sortByDesc('_sort_at')->values();
        $truncated = $sorted->count() > $limit;
        $items = $sorted
            ->take($limit)
            ->map(fn (array $row): array => collect($row)->except('_sort_at')->all())
            ->all();

        return [
            'status' => $items === [] ? 'not_found' : 'found',
            'timezone' => DateTimePresenter::accountTimezone($account),
            'period' => [
                'date_from' => $fromDate,
                'date_to' => $toDate,
            ],
            'filters' => [
                'kind' => $kind,
                'status' => $status,
                'location_id' => $locationId,
                'query_applied' => $query !== '',
            ],
            'returned' => count($items),
            'truncated' => $truncated,
            'items' => $items,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function customerPaymentRows(
        Account $account,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?int $locationId,
        string $search,
        ?string $status,
        int $limit,
    ): Collection {
        $statuses = array_column(CustomerPurchaseStatus::cases(), 'value');

        return $this->customerPayments($account, $startsAt, $endsAt, $locationId)
            ->with([
                'customer:id,account_id,name,phone,email',
                'location:id,account_id,name',
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $escaped = addcslashes($search, '\\%_');
                $query->where(function (Builder $query) use ($escaped): void {
                    $query
                        ->where('order_id', 'like', '%'.$escaped.'%')
                        ->orWhere('plan_name', 'like', '%'.$escaped.'%')
                        ->orWhereHas('customer', fn (Builder $query): Builder => $query
                            ->where('name', 'like', '%'.$escaped.'%')
                            ->orWhere('phone', 'like', '%'.$escaped.'%')
                            ->orWhere('email', 'like', '%'.$escaped.'%'));
                });
            })
            ->when($status !== null && in_array($status, $statuses, true), fn (Builder $query): Builder => $query->where('status', $status))
            ->when($status !== null && ! in_array($status, $statuses, true), fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->effectiveNewestFirst()
            ->limit($limit + 1)
            ->get()
            ->map(fn (CustomerPurchase $payment): array => [
                '_sort_at' => $payment->effectiveOccurredAt()?->getTimestamp() ?? 0,
                'kind' => 'customer_payment',
                'payment_id' => $payment->id,
                'reference' => $payment->order_id,
                'status' => $payment->status->value,
                'occurred_at' => $this->occurredAt($account, $payment->effectiveOccurredAt()),
                'amount' => $this->money((int) $payment->amount_cents, (string) $payment->currency),
                'payment_method' => $payment->isManualCashStudioPayment()
                    ? CustomerPurchase::PaymentMethodCash
                    : CustomerPurchase::PaymentMethodOnline,
                'provider' => $payment->provider,
                'description' => $payment->plan_name,
                'location' => $payment->location ? [
                    'location_id' => $payment->location->id,
                    'name' => $payment->location->name,
                ] : null,
                'customer' => $this->customer($payment->customer),
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function eventPaymentRows(
        Account $account,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?int $locationId,
        string $search,
        ?string $status,
        int $limit,
    ): Collection {
        $statuses = array_column(EventOrderStatus::cases(), 'value');

        return $this->eventPayments($account, $startsAt, $endsAt, $locationId)
            ->with(['event:id,account_id,location_id,slug,title'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $escaped = addcslashes($search, '\\%_');
                $query->where(fn (Builder $query): Builder => $query
                    ->where('order_id', 'like', '%'.$escaped.'%')
                    ->orWhere('buyer_name', 'like', '%'.$escaped.'%')
                    ->orWhere('buyer_email', 'like', '%'.$escaped.'%')
                    ->orWhere('buyer_phone', 'like', '%'.$escaped.'%')
                    ->orWhereHas('event', fn (Builder $query): Builder => $query->where('title', 'like', '%'.$escaped.'%')));
            })
            ->when($status !== null && in_array($status, $statuses, true), fn (Builder $query): Builder => $query->where('status', $status))
            ->when($status !== null && ! in_array($status, $statuses, true), fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->orderByRaw('COALESCE(paid_at, created_at) DESC')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get()
            ->map(fn (EventOrder $payment): array => [
                '_sort_at' => ($payment->paid_at ?? $payment->created_at)?->getTimestamp() ?? 0,
                'kind' => 'event_payment',
                'payment_id' => $payment->id,
                'reference' => $payment->order_id,
                'status' => $payment->status->value,
                'occurred_at' => $this->occurredAt($account, $payment->paid_at ?? $payment->created_at),
                'amount' => $this->money((int) $payment->amount_cents, (string) $payment->currency),
                'payment_method' => CustomerPurchase::PaymentMethodOnline,
                'provider' => $payment->provider,
                'event' => $payment->event ? [
                    'event_id' => $payment->event->id,
                    'slug' => $payment->event->slug,
                    'title' => $payment->event->title,
                ] : null,
                'buyer' => [
                    'name' => $payment->buyer_name,
                    'phone_masked' => $this->maskedContact->phone($payment->buyer_phone),
                    'email_masked' => $this->maskedContact->email($payment->buyer_email),
                ],
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function expenseRows(
        Account $account,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?int $locationId,
        string $search,
        ?string $status,
        int $limit,
    ): Collection {
        return $this->expenses($account, $startsAt, $endsAt, $locationId)
            ->with([
                'category:id,account_id,name',
                'location:id,account_id,name',
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $escaped = addcslashes($search, '\\%_');
                $query->where(fn (Builder $query): Builder => $query
                    ->where('reason', 'like', '%'.$escaped.'%')
                    ->orWhereHas('category', fn (Builder $query): Builder => $query->where('name', 'like', '%'.$escaped.'%')));
            })
            ->when($status === StudioExpense::StatusActive, fn (Builder $query): Builder => $query->active())
            ->when($status === StudioExpense::StatusVoided, fn (Builder $query): Builder => $query->voided())
            ->when($status !== null && ! in_array($status, StudioExpense::statuses(), true), fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get()
            ->map(fn (StudioExpense $expense): array => [
                '_sort_at' => $expense->occurred_at?->getTimestamp() ?? 0,
                'kind' => 'operational_expense',
                'payment_id' => $expense->id,
                'reference' => null,
                'status' => $expense->status(),
                'occurred_at' => $this->occurredAt($account, $expense->occurred_at),
                'amount' => $this->money((int) $expense->amount_cents, (string) $expense->currency),
                'payment_method' => $expense->payment_method,
                'category' => $expense->category?->name,
                'location' => $expense->location ? [
                    'location_id' => $expense->location->id,
                    'name' => $expense->location->name,
                ] : null,
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function cashMovementRows(
        Account $account,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?int $locationId,
        string $search,
        int $limit,
    ): Collection {
        return StudioCashEntry::query()
            ->whereBelongsTo($account)
            ->with(['location:id,account_id,name'])
            ->whereBetween('occurred_at', [$startsAt, $endsAt])
            ->whereNotIn('purpose', [
                StudioCashEntry::PurposeOperationalExpense,
                StudioCashEntry::PurposeExpenseReversal,
            ])
            ->when($locationId, fn (Builder $query, int $id): Builder => $query->where('location_id', $id))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $escaped = addcslashes($search, '\\%_');
                $query->where(fn (Builder $query): Builder => $query
                    ->where('reason', 'like', '%'.$escaped.'%')
                    ->orWhere('purpose', 'like', '%'.$escaped.'%'));
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get()
            ->map(fn (StudioCashEntry $entry): array => [
                '_sort_at' => $entry->occurred_at?->getTimestamp() ?? 0,
                'kind' => 'cash_movement',
                'payment_id' => $entry->id,
                'reference' => null,
                'status' => $entry->direction,
                'occurred_at' => $this->occurredAt($account, $entry->occurred_at),
                'amount' => $this->money((int) $entry->amount_cents, (string) $entry->currency),
                'purpose' => $entry->purpose,
                'location' => $entry->location ? [
                    'location_id' => $entry->location->id,
                    'name' => $entry->location->name,
                ] : null,
            ]);
    }

    private function customerPayments(
        Account $account,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?int $locationId,
    ): Builder {
        return CustomerPurchase::query()
            ->whereBelongsTo($account)
            ->withinEffectiveDateRange($startsAt, $endsAt)
            ->when($locationId, fn (Builder $query, int $id): Builder => $query->where('location_id', $id));
    }

    private function eventPayments(
        Account $account,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?int $locationId,
    ): Builder {
        return EventOrder::query()
            ->whereBelongsTo($account)
            ->where(fn (Builder $query): Builder => $query
                ->whereBetween('paid_at', [$startsAt, $endsAt])
                ->orWhere(fn (Builder $query): Builder => $query
                    ->whereNull('paid_at')
                    ->whereBetween('created_at', [$startsAt, $endsAt])))
            ->when($locationId, fn (Builder $query, int $id): Builder => $query
                ->whereHas('event', fn (Builder $query): Builder => $query->where('location_id', $id)));
    }

    private function expenses(
        Account $account,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?int $locationId,
    ): Builder {
        return StudioExpense::query()
            ->whereBelongsTo($account)
            ->whereBetween('occurred_at', [$startsAt, $endsAt])
            ->when($locationId, fn (Builder $query, int $id): Builder => $query->where('location_id', $id));
    }

    /**
     * @return array{0: string, 1: string, 2: CarbonImmutable, 3: CarbonImmutable}
     */
    private function period(Account $account, array $arguments): array
    {
        $timezone = DateTimePresenter::accountTimezone($account);
        $today = CarbonImmutable::now($timezone);
        $fromDate = filled($arguments['date_from'] ?? null)
            ? (string) $arguments['date_from']
            : $today->toDateString();
        $toDate = filled($arguments['date_to'] ?? null)
            ? (string) $arguments['date_to']
            : $today->toDateString();
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $fromDate, $timezone);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $toDate, $timezone);

        if ($from->greaterThan($to) || $from->diffInDays($to) > self::MaximumPeriodDays) {
            throw ValidationException::withMessages([
                'date_to' => 'The payment period must end on or after date_from and may not exceed 366 days.',
            ]);
        }

        return [
            $fromDate,
            $toDate,
            $from->startOfDay()->timezone((string) config('app.timezone')),
            $to->endOfDay()->timezone((string) config('app.timezone')),
        ];
    }

    private function location(Account $account, mixed $locationId): ?Location
    {
        if (blank($locationId)) {
            return null;
        }

        $location = $account->locations()
            ->select(['id', 'account_id', 'name'])
            ->whereKey((int) $locationId)
            ->first();

        if (! $location) {
            throw ValidationException::withMessages([
                'location_id' => 'The selected location does not belong to this studio.',
            ]);
        }

        return $location;
    }

    /**
     * @return Collection<int, array{location: Location, manual_cash_by_currency: array<string, int>, cash_in_by_currency: array<string, int>, cash_out_by_currency: array<string, int>, balance_by_currency: array<string, int>}>
     */
    private function cashBalances(Account $account, ?int $locationId): Collection
    {
        $locations = $account->locations()
            ->when($locationId, fn (Builder $query, int $id): Builder => $query->whereKey($id))
            ->orderBy('name')
            ->get(['id', 'account_id', 'name']);
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

        return $locations->map(function (Location $location) use ($manualCashByLocation, $cashEntriesByLocation): array {
            $entries = $cashEntriesByLocation->get($location->id, collect());
            $manualCash = $this->currencyTotalsFromRows($manualCashByLocation->get($location->id, collect()));
            $cashIn = $this->currencyTotalsFromRows($entries->where('direction', StudioCashEntry::DirectionIn));
            $cashOut = $this->currencyTotalsFromRows($entries->where('direction', StudioCashEntry::DirectionOut));

            return [
                'location' => $location,
                'manual_cash_by_currency' => $manualCash,
                'cash_in_by_currency' => $cashIn,
                'cash_out_by_currency' => $cashOut,
                'balance_by_currency' => $this->subtractCurrencyTotals(
                    $this->mergeCurrencyTotals($manualCash, $cashIn),
                    $cashOut,
                ),
            ];
        });
    }

    private function fiscalFailureCount(Builder $customerPayments, Builder $eventPayments): int
    {
        return FiscalReceipt::query()
            ->where('status', FiscalReceiptStatus::Failed->value)
            ->where('payment_type', (new CustomerPurchase)->getMorphClass())
            ->whereIn('payment_id', (clone $customerPayments)->select('id'))
            ->count()
            + FiscalReceipt::query()
                ->where('status', FiscalReceiptStatus::Failed->value)
                ->where('payment_type', (new EventOrder)->getMorphClass())
                ->whereIn('payment_id', (clone $eventPayments)->select('id'))
                ->count();
    }

    /**
     * @return array<string, int>
     */
    private function countsByStatus(Builder $query): array
    {
        return $query
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->sortKeys()
            ->all();
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
            ->map(fn (mixed $amount): int => (int) $amount)
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
            foreach ($currencyTotals as $currency => $amount) {
                $merged[$currency] = ($merged[$currency] ?? 0) + $amount;
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

        foreach ($subtrahend as $currency => $amount) {
            $result[$currency] = ($result[$currency] ?? 0) - $amount;
        }

        ksort($result);

        return $result;
    }

    /**
     * @param  array<string, int>  $totals
     * @return array<int, array{currency: string, amount_cents: int, amount: string}>
     */
    private function moneyTotals(array $totals): array
    {
        return collect($totals)
            ->map(fn (int $amount, string $currency): array => $this->money($amount, $currency))
            ->values()
            ->all();
    }

    /**
     * @return array{currency: string, amount_cents: int, amount: string}
     */
    private function money(int $amountCents, string $currency): array
    {
        return [
            'currency' => $currency,
            'amount_cents' => $amountCents,
            'amount' => number_format($amountCents / 100, 2, '.', ''),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function customer(?Customer $customer): ?array
    {
        if (! $customer) {
            return null;
        }

        return [
            'customer_id' => $customer->id,
            'name' => $customer->name,
            'phone_masked' => $this->maskedContact->phone($customer->phone),
            'email_masked' => $this->maskedContact->email($customer->email),
        ];
    }

    private function occurredAt(Account $account, ?CarbonInterface $occurredAt): ?string
    {
        return $occurredAt?->copy()
            ->timezone(DateTimePresenter::accountTimezone($account))
            ->toIso8601String();
    }
}

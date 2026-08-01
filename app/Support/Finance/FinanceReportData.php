<?php

namespace App\Support\Finance;

use App\Enums\CustomerPurchaseStatus;
use App\Enums\EventOrderStatus;
use App\Models\Account;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseRefund;
use App\Models\EventOrder;
use App\Models\FinanceEpoch;
use App\Models\StudioCashEntry;
use App\Models\StudioExpense;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FinanceReportData
{
    /**
     * @param  array{date_from: string, date_to: string, location_id: int|null}  $filters
     * @return array<string, mixed>
     */
    public function forAccount(
        Account $account,
        array $filters,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?FinanceEpoch $epoch,
    ): array {
        $customerPayments = CustomerPurchase::query()
            ->whereBelongsTo($account)
            ->withinEffectiveDateRange($startsAt, $endsAt)
            ->where('status', CustomerPurchaseStatus::PaymentPaid->value)
            ->when($filters['location_id'], fn (Builder $query, int $locationId): Builder => $query->where('location_id', $locationId))
            ->with(['customer:id,name', 'location:id,name'])
            ->get();
        $eventPayments = EventOrder::query()
            ->whereBelongsTo($account)
            ->whereBetween('paid_at', [$startsAt, $endsAt])
            ->whereIn('status', [
                EventOrderStatus::Paid->value,
                EventOrderStatus::RefundRequired->value,
                EventOrderStatus::PaidRequiresRefund->value,
                EventOrderStatus::Refunded->value,
            ])
            ->when($filters['location_id'], fn (Builder $query, int $locationId): Builder => $query
                ->whereHas('event', fn (Builder $query): Builder => $query->where('location_id', $locationId)))
            ->with(['event:id,title,location_id'])
            ->get();
        $purchaseRefunds = CustomerPurchaseRefund::query()
            ->whereBelongsTo($account)
            ->whereBetween('refunded_at', [$startsAt, $endsAt])
            ->when($filters['location_id'], fn (Builder $query, int $locationId): Builder => $query->where('location_id', $locationId))
            ->with(['customerPurchase.customer:id,name', 'location:id,name'])
            ->get();
        $eventRefunds = EventOrder::query()
            ->whereBelongsTo($account)
            ->where('status', EventOrderStatus::Refunded->value)
            ->whereBetween('refunded_at', [$startsAt, $endsAt])
            ->when($filters['location_id'], fn (Builder $query, int $locationId): Builder => $query
                ->whereHas('event', fn (Builder $query): Builder => $query->where('location_id', $locationId)))
            ->with(['event:id,title,location_id'])
            ->get();
        $expenses = StudioExpense::query()
            ->whereBelongsTo($account)
            ->active()
            ->whereBetween('occurred_at', [$startsAt, $endsAt])
            ->when($filters['location_id'], fn (Builder $query, int $locationId): Builder => $query
                ->where('expense_location_id', $locationId))
            ->with(['category:id,name', 'expenseLocation:id,name'])
            ->get();
        $ownerDeposits = $this->ownerCashEntries(
            $account,
            $filters,
            $startsAt,
            $endsAt,
            $epoch,
            StudioCashEntry::PurposeDeposit,
        );
        $ownerWithdrawals = $this->ownerCashEntries(
            $account,
            $filters,
            $startsAt,
            $endsAt,
            $epoch,
            StudioCashEntry::PurposeOwnerWithdrawal,
        );
        $paymentTotals = $this->mergeTotals(
            $this->totals($customerPayments),
            $this->totals($eventPayments),
        );
        $refundTotals = $this->mergeTotals(
            $this->totals($purchaseRefunds),
            $this->totals($eventRefunds),
        );
        $expenseTotals = $this->totals($expenses);
        $ownerDepositTotals = $this->totals($ownerDeposits);
        $ownerWithdrawalTotals = $this->totals($ownerWithdrawals);

        return [
            'totals' => [
                'payments' => $paymentTotals,
                'refunds' => $refundTotals,
                'expenses' => $expenseTotals,
                'owner_deposits' => $ownerDepositTotals,
                'owner_withdrawals' => $ownerWithdrawalTotals,
                'operating_cash_result' => $this->subtractTotals(
                    $this->subtractTotals($paymentTotals, $refundTotals),
                    $expenseTotals,
                ),
            ],
            'sections' => [
                'payments' => $customerPayments
                    ->map(fn (CustomerPurchase $purchase): array => [
                        'occurred_at' => $purchase->effectiveOccurredAt(),
                        'label' => $purchase->customer?->name ?? $purchase->plan_name,
                        'details' => $purchase->plan_name,
                        'location' => $purchase->location?->name,
                        'amount_cents' => (int) $purchase->amount_cents,
                        'currency' => (string) $purchase->currency,
                    ])
                    ->concat($eventPayments->map(fn (EventOrder $order): array => [
                        'occurred_at' => $order->paid_at ?? $order->created_at,
                        'label' => $order->buyer_name,
                        'details' => $order->event?->title,
                        'location' => null,
                        'amount_cents' => (int) $order->amount_cents,
                        'currency' => (string) $order->currency,
                    ]))
                    ->sortByDesc('occurred_at')
                    ->values(),
                'refunds' => $purchaseRefunds
                    ->map(fn (CustomerPurchaseRefund $refund): array => [
                        'occurred_at' => $refund->effectiveOccurredAt(),
                        'label' => $refund->customerPurchase?->customer?->name,
                        'details' => $refund->reason,
                        'location' => $refund->location?->name,
                        'amount_cents' => (int) $refund->amount_cents,
                        'currency' => (string) $refund->currency,
                    ])
                    ->concat($eventRefunds->map(fn (EventOrder $order): array => [
                        'occurred_at' => $order->refunded_at,
                        'label' => $order->buyer_name,
                        'details' => $order->event?->title,
                        'location' => null,
                        'amount_cents' => (int) $order->amount_cents,
                        'currency' => (string) $order->currency,
                    ]))
                    ->sortByDesc('occurred_at')
                    ->values(),
                'expenses' => $expenses
                    ->map(fn (StudioExpense $expense): array => [
                        'occurred_at' => $expense->occurred_at,
                        'label' => $expense->category?->name,
                        'details' => $expense->reason,
                        'location' => $expense->expenseLocation?->name,
                        'amount_cents' => (int) $expense->amount_cents,
                        'currency' => (string) $expense->currency,
                    ])
                    ->sortByDesc('occurred_at')
                    ->values(),
                'owner_deposits' => $this->cashEntryRows($ownerDeposits),
                'owner_withdrawals' => $this->cashEntryRows($ownerWithdrawals),
            ],
        ];
    }

    /**
     * @param  array{date_from: string, date_to: string, location_id: int|null}  $filters
     * @return Collection<int, StudioCashEntry>
     */
    private function ownerCashEntries(
        Account $account,
        array $filters,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?FinanceEpoch $epoch,
        string $purpose,
    ): Collection {
        return StudioCashEntry::query()
            ->whereBelongsTo($account)
            ->where('purpose', $purpose)
            ->whereBetween('occurred_at', [$startsAt, $endsAt])
            ->when($epoch, fn (Builder $query, FinanceEpoch $epoch): Builder => $query->where('finance_epoch_id', $epoch->id))
            ->when($filters['location_id'], fn (Builder $query, int $locationId): Builder => $query->where('location_id', $locationId))
            ->with('location:id,name')
            ->get();
    }

    /**
     * @param  Collection<int, StudioCashEntry>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function cashEntryRows(Collection $entries): Collection
    {
        return $entries
            ->map(fn (StudioCashEntry $entry): array => [
                'occurred_at' => $entry->occurred_at,
                'label' => $entry->actor_name,
                'details' => $entry->reason,
                'location' => $entry->location?->name,
                'amount_cents' => (int) $entry->amount_cents,
                'currency' => (string) $entry->currency,
            ])
            ->sortByDesc('occurred_at')
            ->values();
    }

    /**
     * @param  Collection<int, object>  $items
     * @return array<string, int>
     */
    private function totals(Collection $items): array
    {
        return $items
            ->groupBy(fn (object $item): string => strtoupper((string) $item->currency))
            ->map(fn (Collection $currencyItems): int => (int) $currencyItems->sum('amount_cents'))
            ->sortKeys()
            ->all();
    }

    /**
     * @param  array<string, int>  ...$totals
     * @return array<string, int>
     */
    private function mergeTotals(array ...$totals): array
    {
        return collect($totals)
            ->flatMap(fn (array $amounts): Collection => collect($amounts)
                ->map(fn (int $amount, string $currency): array => compact('currency', 'amount'))
                ->values())
            ->groupBy('currency')
            ->map(fn (Collection $amounts): int => (int) $amounts->sum('amount'))
            ->sortKeys()
            ->all();
    }

    /**
     * @param  array<string, int>  $minuend
     * @param  array<string, int>  $subtrahend
     * @return array<string, int>
     */
    private function subtractTotals(array $minuend, array $subtrahend): array
    {
        return collect(array_unique([...array_keys($minuend), ...array_keys($subtrahend)]))
            ->mapWithKeys(fn (string $currency): array => [
                $currency => (int) ($minuend[$currency] ?? 0) - (int) ($subtrahend[$currency] ?? 0),
            ])
            ->sortKeys()
            ->all();
    }
}

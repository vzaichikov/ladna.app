<?php

namespace App\Support\Payments;

use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseRefund;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AccountPaymentHistory
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(
        Account $account,
        array $filters,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): LengthAwarePaginator {
        $paymentEntries = $this->paymentQuery($account, $filters, $startsAt, $endsAt)
            ->selectRaw('? as entry_type, id as entry_id, COALESCE(paid_at, started_at, created_at) as occurred_at', ['payment'])
            ->toBase();
        $refundEntries = $this->refundQuery($account, $filters, $startsAt, $endsAt)
            ->selectRaw('? as entry_type, id as entry_id, refunded_at as occurred_at', ['refund'])
            ->toBase();
        $entries = DB::query()
            ->fromSub($paymentEntries->unionAll($refundEntries), 'payment_history')
            ->orderByDesc('occurred_at')
            ->orderByDesc('entry_type')
            ->orderByDesc('entry_id')
            ->paginate(20, ['*'], 'payments_page')
            ->withQueryString();
        $paymentIds = $entries->getCollection()
            ->where('entry_type', 'payment')
            ->pluck('entry_id');
        $refundIds = $entries->getCollection()
            ->where('entry_type', 'refund')
            ->pluck('entry_id');
        $payments = CustomerPurchase::query()
            ->whereKey($paymentIds)
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
                'refunds',
            ])
            ->get()
            ->keyBy('id');
        $refunds = CustomerPurchaseRefund::query()
            ->whereKey($refundIds)
            ->with([
                'location',
                'cashLocation',
                'cashEntry',
                'fiscalReceipt',
                'customerPurchase.customer',
                'customerPurchase.location',
                'customerPurchase.fiscalReceipt',
            ])
            ->get()
            ->keyBy('id');

        $entries->setCollection(
            $entries->getCollection()
                ->map(function (object $entry) use ($payments, $refunds): array {
                    $record = $entry->entry_type === 'refund'
                        ? $refunds->get($entry->entry_id)
                        : $payments->get($entry->entry_id);

                    return [
                        'type' => (string) $entry->entry_type,
                        'record' => $record,
                    ];
                })
                ->filter(fn (array $entry): bool => $entry['record'] !== null)
                ->values(),
        );

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paymentQuery(
        Account $account,
        array $filters,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): Builder {
        return CustomerPurchase::query()
            ->whereBelongsTo($account)
            ->withinEffectiveDateRange($startsAt, $endsAt)
            ->when($filters['status'] === CustomerPurchaseRefund::StatusRecorded, fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->when($filters['search'], function (Builder $query, string $search) use ($account): void {
                $query->whereIn('customer_id', Customer::query()
                    ->whereBelongsTo($account)
                    ->where(function (Builder $query) use ($search): void {
                        $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    })
                    ->select('id'));
            })
            ->when($filters['payment_method'] === CustomerPurchase::PaymentMethodCash, fn (Builder $query): Builder => $query->whereIn('payment_source', [
                CustomerPurchase::SourceManualCashClassPass,
                CustomerPurchase::SourceManualCashBooking,
            ]))
            ->when($filters['payment_method'] === CustomerPurchase::PaymentMethodOnline, fn (Builder $query): Builder => $query->where('payment_source', CustomerPurchase::SourceOnlineCheckout))
            ->when(
                $filters['status'] !== CustomerPurchaseRefund::StatusRecorded ? $filters['status'] : null,
                fn (Builder $query, string $status): Builder => $query->where('status', $status),
            )
            ->when($filters['provider'], fn (Builder $query, string $provider): Builder => $query->where('provider', $provider))
            ->when($filters['location_id'], fn (Builder $query, int $locationId): Builder => $query->where('location_id', $locationId));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function refundQuery(
        Account $account,
        array $filters,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): Builder {
        return CustomerPurchaseRefund::query()
            ->whereBelongsTo($account)
            ->whereBetween('refunded_at', [$startsAt, $endsAt])
            ->when($filters['search'], function (Builder $query, string $search) use ($account): void {
                $customerIds = Customer::query()
                    ->whereBelongsTo($account)
                    ->where(function (Builder $query) use ($search): void {
                        $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    })
                    ->select('id');

                $query->whereIn('customer_purchase_id', CustomerPurchase::query()
                    ->whereBelongsTo($account)
                    ->whereIn('customer_id', $customerIds)
                    ->select('id'));
            })
            ->when($filters['payment_method'] === CustomerPurchase::PaymentMethodCash, fn (Builder $query): Builder => $query->where('method', CustomerPurchaseRefund::MethodCash))
            ->when($filters['payment_method'] === CustomerPurchase::PaymentMethodOnline, fn (Builder $query): Builder => $query->where('method', CustomerPurchaseRefund::MethodCashless))
            ->when($filters['status'] && $filters['status'] !== CustomerPurchaseRefund::StatusRecorded, fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->when($filters['provider'], fn (Builder $query, string $provider): Builder => $query->whereIn(
                'customer_purchase_id',
                CustomerPurchase::query()
                    ->whereBelongsTo($account)
                    ->where('provider', $provider)
                    ->select('id'),
            ))
            ->when($filters['location_id'], fn (Builder $query, int $locationId): Builder => $query->where('location_id', $locationId));
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\CustomerPurchaseStatus;
use App\Enums\FiscalReceiptStatus;
use App\Models\Account;
use App\Models\CustomerPurchase;
use App\Models\StudioCashEntry;
use App\Support\Fiscalization\FiscalizationAvailability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class AccountPaymentController extends Controller
{
    public function index(Request $request, Account $account, FiscalizationAvailability $fiscalization): View
    {
        $this->authorize('view', $account);
        $canManageStudioCashflow = $account->isOwnedBy($request->user())
            || ($request->user()?->can('manageStudioCashflow', $account) ?? false);
        abort_unless($canManageStudioCashflow, 403);

        $status = $this->statusFilter($request->query('status'));
        $provider = $this->providerFilter($request->query('provider'), $account);
        $locationId = $this->locationFilter($request->query('location_id'), $account);
        $fiscalizationEnabled = $fiscalization->enabledForAccount($account);
        $baseQuery = CustomerPurchase::query()
            ->whereBelongsTo($account)
            ->when($status, fn (Builder $query): Builder => $query->where('status', $status))
            ->when($provider, fn (Builder $query): Builder => $query->where('provider', $provider))
            ->when($locationId, fn (Builder $query): Builder => $query->where('location_id', $locationId));

        $payments = (clone $baseQuery)
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
            ->newestFirst()
            ->paginate(20)
            ->withQueryString();
        $cashEntries = $account->studioCashEntries()
            ->with('location')
            ->when($locationId, fn (Builder $query): Builder => $query->where('location_id', $locationId))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->take(20)
            ->get();

        return view('accounts.payments.index', [
            'account' => $account,
            'payments' => $payments,
            'cashEntries' => $cashEntries,
            'status' => $status,
            'provider' => $provider,
            'locationId' => $locationId,
            'locations' => $account->locations()->orderBy('name')->get(),
            'providers' => $this->providerOptions($account),
            'statuses' => CustomerPurchaseStatus::cases(),
            'fiscalizationEnabled' => $fiscalizationEnabled,
            'canManageStudioCashflow' => $canManageStudioCashflow,
            'cashBalances' => $this->cashBalances($account, $locationId),
            'stats' => $this->stats($baseQuery, $account, $fiscalizationEnabled, $locationId),
        ]);
    }

    private function statusFilter(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return in_array($value, array_column(CustomerPurchaseStatus::cases(), 'value'), true) ? $value : null;
    }

    private function providerFilter(mixed $value, Account $account): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        if (array_key_exists($value, config('integrations.providers', []))) {
            return $value;
        }

        return $account->customerPurchases()->where('provider', $value)->exists() ? $value : null;
    }

    private function locationFilter(mixed $value, Account $account): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $locationId = (int) $value;

        return $account->locations()->whereKey($locationId)->exists() ? $locationId : null;
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
     * @return array{total: int, paid_amount_cents: int, pending: int, failed: int, fiscal_failed: int, cash_balance_cents: int}
     */
    private function stats(Builder $baseQuery, Account $account, bool $fiscalizationEnabled, ?int $locationId): array
    {
        $cashBalances = $this->cashBalances($account, $locationId);

        return [
            'total' => (clone $baseQuery)->count(),
            'paid_amount_cents' => (clone $baseQuery)
                ->where('status', CustomerPurchaseStatus::PaymentPaid->value)
                ->sum('amount_cents'),
            'pending' => (clone $baseQuery)
                ->whereIn('status', [
                    CustomerPurchaseStatus::PaymentStarted->value,
                    CustomerPurchaseStatus::PaymentPending->value,
                ])
                ->count(),
            'failed' => (clone $baseQuery)
                ->whereIn('status', [
                    CustomerPurchaseStatus::PaymentFailed->value,
                    CustomerPurchaseStatus::PaymentCancelled->value,
                    CustomerPurchaseStatus::PaymentExpired->value,
                ])
                ->count(),
            'fiscal_failed' => $fiscalizationEnabled
                ? $account->fiscalReceipts()->where('status', FiscalReceiptStatus::Failed->value)->count()
                : 0,
            'cash_balance_cents' => $cashBalances->sum('balance_cents'),
        ];
    }

    /**
     * @return Collection<int, array{location: mixed, manual_cash_cents: int, cash_in_cents: int, cash_out_cents: int, balance_cents: int}>
     */
    private function cashBalances(Account $account, ?int $locationId): Collection
    {
        $locations = $account->locations()
            ->when($locationId, fn (Builder $query): Builder => $query->whereKey($locationId))
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
            ->selectRaw('location_id, SUM(amount_cents) as amount_cents')
            ->groupBy('location_id')
            ->pluck('amount_cents', 'location_id');
        $cashEntriesByLocation = $account->studioCashEntries()
            ->whereIn('location_id', $locationIds)
            ->selectRaw('location_id, direction, SUM(amount_cents) as amount_cents')
            ->groupBy('location_id', 'direction')
            ->get()
            ->groupBy('location_id');

        return $locations->map(function ($location) use ($manualCashByLocation, $cashEntriesByLocation): array {
            $entries = $cashEntriesByLocation->get($location->id, collect());
            $cashInCents = (int) ($entries->firstWhere('direction', StudioCashEntry::DirectionIn)?->amount_cents ?? 0);
            $cashOutCents = (int) ($entries->firstWhere('direction', StudioCashEntry::DirectionOut)?->amount_cents ?? 0);
            $manualCashCents = (int) ($manualCashByLocation[$location->id] ?? 0);

            return [
                'location' => $location,
                'manual_cash_cents' => $manualCashCents,
                'cash_in_cents' => $cashInCents,
                'cash_out_cents' => $cashOutCents,
                'balance_cents' => $manualCashCents + $cashInCents - $cashOutCents,
            ];
        });
    }
}

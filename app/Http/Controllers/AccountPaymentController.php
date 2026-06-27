<?php

namespace App\Http\Controllers;

use App\Enums\CustomerPurchaseStatus;
use App\Enums\FiscalReceiptStatus;
use App\Models\Account;
use App\Models\CustomerPurchase;
use App\Support\Fiscalization\FiscalizationAvailability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountPaymentController extends Controller
{
    public function index(Request $request, Account $account, FiscalizationAvailability $fiscalization): View
    {
        $this->authorize('view', $account);
        abort_unless($account->isOwnedBy($request->user()), 403);

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
            ->with(['customer', 'location', 'classPassPlan', 'customerClassPass', 'fiscalReceipt'])
            ->newestFirst()
            ->paginate(20)
            ->withQueryString();

        return view('accounts.payments.index', [
            'account' => $account,
            'payments' => $payments,
            'status' => $status,
            'provider' => $provider,
            'locationId' => $locationId,
            'locations' => $account->locations()->orderBy('name')->get(),
            'providers' => $this->providerOptions($account),
            'statuses' => CustomerPurchaseStatus::cases(),
            'fiscalizationEnabled' => $fiscalizationEnabled,
            'stats' => $this->stats($baseQuery, $account, $fiscalizationEnabled),
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
     * @return array{total: int, paid_amount_cents: int, pending: int, failed: int, fiscal_failed: int}
     */
    private function stats(Builder $baseQuery, Account $account, bool $fiscalizationEnabled): array
    {
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
        ];
    }
}

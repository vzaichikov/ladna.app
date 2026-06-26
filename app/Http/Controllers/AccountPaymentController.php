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
        $provider = $this->providerFilter($request->query('provider'));
        $fiscalizationEnabled = $fiscalization->enabledForAccount($account);
        $baseQuery = CustomerPurchase::query()
            ->whereBelongsTo($account)
            ->when($status, fn (Builder $query): Builder => $query->where('status', $status))
            ->when($provider, fn (Builder $query): Builder => $query->where('provider', $provider));

        $payments = (clone $baseQuery)
            ->with(['customer', 'classPassPlan', 'customerClassPass', 'fiscalReceipt'])
            ->newestFirst()
            ->paginate(20)
            ->withQueryString();

        return view('accounts.payments.index', [
            'account' => $account,
            'payments' => $payments,
            'status' => $status,
            'provider' => $provider,
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

    private function providerFilter(mixed $value): ?string
    {
        return is_string($value) && array_key_exists($value, config('integrations.providers', [])) ? $value : null;
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
                $provider => config('integrations.providers.'.$provider.'.label', $provider),
            ])
            ->all();
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

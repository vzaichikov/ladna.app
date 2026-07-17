<?php

namespace App\Http\Controllers\Platform;

use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\FiscalReceiptStatus;
use App\Http\Controllers\Controller;
use App\Models\AccountSubscriptionPayment;
use App\Models\FiscalReceipt;
use App\Support\Fiscalization\FiscalizationAvailability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function index(Request $request, FiscalizationAvailability $fiscalization): View
    {
        $status = $this->statusFilter($request->query('status'));
        $provider = $this->providerFilter($request->query('provider'));
        $fiscalizationEnabled = $fiscalization->enabledForPlatform();
        $baseQuery = AccountSubscriptionPayment::query()
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('account_id')
                    ->orWhereHas('account', fn (Builder $query): Builder => $query->operational());
            })
            ->when($status, fn (Builder $query): Builder => $query->where('status', $status))
            ->when($provider, fn (Builder $query): Builder => $query->where('provider', $provider));

        $payments = (clone $baseQuery)
            ->with(['account', 'subscription', 'plan', 'fiscalReceipt'])
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('platform.payments.index', [
            'payments' => $payments,
            'status' => $status,
            'provider' => $provider,
            'providers' => $this->providerOptions(),
            'statuses' => AccountSubscriptionPaymentStatus::cases(),
            'fiscalizationEnabled' => $fiscalizationEnabled,
            'stats' => $this->stats($baseQuery, $fiscalizationEnabled),
        ]);
    }

    private function statusFilter(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return in_array($value, array_column(AccountSubscriptionPaymentStatus::cases(), 'value'), true) ? $value : null;
    }

    private function providerFilter(mixed $value): ?string
    {
        return is_string($value) && array_key_exists($value, config('integrations.providers', [])) ? $value : null;
    }

    /**
     * @return array<string, string>
     */
    private function providerOptions(): array
    {
        return AccountSubscriptionPayment::query()
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('account_id')
                    ->orWhereHas('account', fn (Builder $query): Builder => $query->operational());
            })
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
    private function stats(Builder $baseQuery, bool $fiscalizationEnabled): array
    {
        return [
            'total' => (clone $baseQuery)->count(),
            'paid_amount_cents' => (clone $baseQuery)
                ->where('status', AccountSubscriptionPaymentStatus::PaymentPaid->value)
                ->sum('amount_cents'),
            'pending' => (clone $baseQuery)
                ->whereIn('status', [
                    AccountSubscriptionPaymentStatus::PaymentStarted->value,
                    AccountSubscriptionPaymentStatus::PaymentPending->value,
                ])
                ->count(),
            'failed' => (clone $baseQuery)
                ->whereIn('status', [
                    AccountSubscriptionPaymentStatus::PaymentFailed->value,
                    AccountSubscriptionPaymentStatus::PaymentCancelled->value,
                    AccountSubscriptionPaymentStatus::PaymentExpired->value,
                ])
                ->count(),
            'fiscal_failed' => $fiscalizationEnabled
                ? FiscalReceipt::query()
                    ->where('scope_type', 'platform')
                    ->where(function (Builder $query): void {
                        $query
                            ->whereNull('account_id')
                            ->orWhereHas('account', fn (Builder $query): Builder => $query->operational());
                    })
                    ->where('status', FiscalReceiptStatus::Failed->value)
                    ->count()
                : 0,
        ];
    }
}

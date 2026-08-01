<?php

namespace App\Http\Controllers\Platform;

use App\Enums\FiscalReceiptStatus;
use App\Enums\SmsTopUpKind;
use App\Enums\SmsTopUpPaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\FiscalReceipt;
use App\Models\SmsTopUpPayment;
use App\Support\Fiscalization\FiscalizationAvailability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SmsPaymentController extends Controller
{
    public function index(Request $request, FiscalizationAvailability $fiscalization): View
    {
        $providers = $this->providerOptions();
        $status = $this->statusFilter($request->query('status'));
        $provider = $this->providerFilter($request->query('provider'), $providers);
        $kind = $this->kindFilter($request->query('kind'));
        $fiscalizationEnabled = $fiscalization->enabledForPlatform();
        $baseQuery = SmsTopUpPayment::query()
            ->whereHas('account', fn (Builder $query): Builder => $query->operational())
            ->when($status, fn (Builder $query): Builder => $query->where('status', $status))
            ->when($provider, fn (Builder $query): Builder => $query->where('provider', $provider))
            ->when($kind, fn (Builder $query): Builder => $query->where('kind', $kind));

        $payments = (clone $baseQuery)
            ->with(['account', 'fiscalReceipt'])
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('platform.sms-payments.index', [
            'payments' => $payments,
            'status' => $status,
            'provider' => $provider,
            'kind' => $kind,
            'providers' => $providers,
            'statuses' => SmsTopUpPaymentStatus::cases(),
            'kinds' => SmsTopUpKind::cases(),
            'fiscalizationEnabled' => $fiscalizationEnabled,
            'stats' => $this->stats($baseQuery, $fiscalizationEnabled),
        ]);
    }

    private function statusFilter(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return in_array($value, array_column(SmsTopUpPaymentStatus::cases(), 'value'), true) ? $value : null;
    }

    /**
     * @param  array<string, string>  $providers
     */
    private function providerFilter(mixed $value, array $providers): ?string
    {
        return is_string($value) && array_key_exists($value, $providers) ? $value : null;
    }

    private function kindFilter(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return in_array($value, array_column(SmsTopUpKind::cases(), 'value'), true) ? $value : null;
    }

    /**
     * @return array<string, string>
     */
    private function providerOptions(): array
    {
        return SmsTopUpPayment::query()
            ->whereHas('account', fn (Builder $query): Builder => $query->operational())
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
            'paid_amount_cents' => (int) (clone $baseQuery)
                ->where('status', SmsTopUpPaymentStatus::PaymentPaid->value)
                ->sum('amount_cents'),
            'pending' => (clone $baseQuery)
                ->whereIn('status', [
                    SmsTopUpPaymentStatus::PaymentStarted->value,
                    SmsTopUpPaymentStatus::PaymentPending->value,
                ])
                ->count(),
            'failed' => (clone $baseQuery)
                ->whereIn('status', [
                    SmsTopUpPaymentStatus::PaymentFailed->value,
                    SmsTopUpPaymentStatus::PaymentCancelled->value,
                    SmsTopUpPaymentStatus::PaymentExpired->value,
                    SmsTopUpPaymentStatus::PaymentReversed->value,
                ])
                ->count(),
            'fiscal_failed' => $fiscalizationEnabled
                ? FiscalReceipt::query()
                    ->where('scope_type', 'platform')
                    ->where('scope_id', 0)
                    ->where('payment_type', (new SmsTopUpPayment)->getMorphClass())
                    ->whereHas('account', fn (Builder $query): Builder => $query->operational())
                    ->where('status', FiscalReceiptStatus::Failed->value)
                    ->count()
                : 0,
        ];
    }
}

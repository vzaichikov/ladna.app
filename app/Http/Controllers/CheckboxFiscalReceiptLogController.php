<?php

namespace App\Http\Controllers;

use App\Enums\FiscalReceiptStatus;
use App\Enums\IntegrationProvider;
use App\Models\Account;
use App\Models\AccountSubscriptionPayment;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseRefund;
use App\Models\EventOrder;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalPaymentAttempt;
use App\Models\FestivalTicketOrder;
use App\Models\FiscalReceipt;
use App\Models\SmsTopUpPayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

class CheckboxFiscalReceiptLogController extends Controller
{
    /** @var array<int, class-string<Model>> */
    private const PaymentTypesWithReference = [
        AccountSubscriptionPayment::class,
        CustomerPurchase::class,
        EventOrder::class,
        FestivalEditionPurchase::class,
        FestivalPaymentAttempt::class,
        FestivalTicketOrder::class,
        SmsTopUpPayment::class,
    ];

    public function __invoke(Request $request, Account $account): View
    {
        abort_unless($account->isOwnedBy($request->user()), 403);

        $search = $request->string('q')->trim()->toString();
        $status = $this->status($request->query('status'));
        $receipts = $account->fiscalReceipts()
            ->where('provider', IntegrationProvider::Checkbox->value)
            ->with(['payment' => function (MorphTo $morphTo): void {
                $paymentColumns = static fn (Builder $query): Builder => $query->select([
                    'id',
                    'order_id',
                    'amount_cents',
                    'currency',
                    'provider',
                ]);

                $morphTo->constrain([
                    AccountSubscriptionPayment::class => $paymentColumns,
                    CustomerPurchase::class => $paymentColumns,
                    EventOrder::class => $paymentColumns,
                    FestivalEditionPurchase::class => $paymentColumns,
                    FestivalPaymentAttempt::class => $paymentColumns,
                    FestivalTicketOrder::class => $paymentColumns,
                    SmsTopUpPayment::class => $paymentColumns,
                    CustomerPurchaseRefund::class => static fn (Builder $query): Builder => $query->select([
                        'id',
                        'customer_purchase_id',
                        'method',
                        'amount_cents',
                        'currency',
                    ]),
                ]);
                $morphTo->morphWith([
                    CustomerPurchaseRefund::class => ['customerPurchase:id,order_id'],
                ]);
            }])
            ->when($search !== '', fn (Builder $query): Builder => $this->applySearch($query, $search))
            ->when($status !== '', fn (Builder $query): Builder => $query->where('status', $status))
            ->latest('created_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (FiscalReceipt $receipt): array => $this->logEntry($receipt));

        return view('integrations.checkbox-logs', [
            'account' => $account,
            'receipts' => $receipts,
            'search' => $search,
            'status' => $status,
            'statuses' => FiscalReceiptStatus::cases(),
        ]);
    }

    private function applySearch(Builder $query, string $search): Builder
    {
        $like = '%'.$search.'%';

        return $query->where(function (Builder $query) use ($like): void {
            $query
                ->where('external_uuid', 'like', $like)
                ->orWhere('provider_receipt_id', 'like', $like)
                ->orWhere('fiscal_number', 'like', $like)
                ->orWhere('last_error', 'like', $like)
                ->orWhereHasMorph(
                    'payment',
                    self::PaymentTypesWithReference,
                    fn (Builder $query): Builder => $query->where('order_id', 'like', $like),
                )
                ->orWhereHasMorph(
                    'payment',
                    CustomerPurchaseRefund::class,
                    fn (Builder $query): Builder => $query->whereHas(
                        'customerPurchase',
                        fn (Builder $query): Builder => $query->where('order_id', 'like', $like),
                    ),
                );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function logEntry(FiscalReceipt $receipt): array
    {
        $payment = $receipt->payment;

        return [
            'status' => $receipt->status,
            'provider_receipt_id' => $receipt->provider_receipt_id,
            'fiscal_number' => $receipt->fiscal_number,
            'attempts' => $receipt->attempts,
            'sent_at' => $receipt->sent_at,
            'fiscalized_at' => $receipt->fiscalized_at,
            'failed_at' => $receipt->failed_at,
            'updated_at' => $receipt->updated_at,
            'reference' => $this->paymentReference($receipt, $payment),
            'source_label' => __($this->sourceLabelKey($receipt->payment_type)),
            'amount_cents' => (int) ($payment?->getAttribute('amount_cents') ?? 0),
            'currency' => (string) ($payment?->getAttribute('currency') ?? 'UAH'),
            'payment_provider' => $this->paymentProvider($payment),
            'safe_provider_status' => $this->safeProviderText($receipt->provider_status),
            'safe_error' => $this->safeProviderText($receipt->last_error),
            'request_summary' => $this->requestSummary($receipt->request_payload ?? []),
            'response_details' => $this->responseDetails($receipt->response_payload ?? []),
        ];
    }

    private function paymentReference(FiscalReceipt $receipt, ?Model $payment): string
    {
        if ($payment instanceof CustomerPurchaseRefund) {
            return ($payment->customerPurchase?->order_id ?? __('app.deleted_record')).'/refund-'.$payment->id;
        }

        return (string) ($payment?->getAttribute('order_id') ?? '#'.$receipt->payment_id);
    }

    private function paymentProvider(?Model $payment): string
    {
        if ($payment instanceof CustomerPurchaseRefund) {
            return __('app.payment_refund_method_'.$payment->method);
        }

        $provider = (string) ($payment?->getAttribute('provider') ?? '');

        return (string) config('integrations.providers.'.$provider.'.label', $provider ?: __('app.not_set'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function requestSummary(array $payload): array
    {
        return [
            'id' => Arr::get($payload, 'id'),
            'total_sum' => Arr::get($payload, 'total_sum'),
            'goods' => collect(Arr::get($payload, 'goods', []))
                ->filter(fn (mixed $item): bool => is_array($item))
                ->map(fn (array $item): array => [
                    'code' => Arr::get($item, 'good.code'),
                    'name' => Arr::get($item, 'good.name'),
                    'price' => Arr::get($item, 'good.price'),
                    'quantity' => Arr::get($item, 'quantity'),
                ])->values()->all(),
            'delivery_channels' => array_values(array_intersect(
                ['email', 'phone'],
                array_keys(Arr::get($payload, 'delivery', [])),
            )),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{message: ?string, status: ?string, validation: array<int, array{location: string, message: string, type: string}>}
     */
    private function responseDetails(array $payload): array
    {
        $validation = collect(Arr::get($payload, 'detail', []))
            ->filter(fn (mixed $detail): bool => is_array($detail))
            ->map(fn (array $detail): array => [
                'location' => $this->safeProviderText(collect(Arr::wrap($detail['loc'] ?? []))->implode('.')) ?? '',
                'message' => $this->safeProviderText((string) ($detail['msg'] ?? '')) ?? '',
                'type' => $this->safeProviderText((string) ($detail['type'] ?? '')) ?? '',
            ])->values()->all();

        return [
            'message' => is_string($payload['message'] ?? null) ? $this->safeProviderText($payload['message']) : null,
            'status' => is_string($payload['status'] ?? null) ? $this->safeProviderText($payload['status']) : null,
            'validation' => $validation,
        ];
    }

    private function sourceLabelKey(string $paymentType): string
    {
        return match ($paymentType) {
            AccountSubscriptionPayment::class => 'app.fiscal_source_subscription',
            CustomerPurchase::class => 'app.fiscal_source_customer_purchase',
            CustomerPurchaseRefund::class => 'app.fiscal_source_customer_refund',
            EventOrder::class => 'app.fiscal_source_event',
            FestivalEditionPurchase::class => 'app.fiscal_source_festival_package',
            FestivalPaymentAttempt::class => 'app.fiscal_source_festival_entry',
            FestivalTicketOrder::class => 'app.fiscal_source_festival_ticket',
            SmsTopUpPayment::class => 'app.fiscal_source_sms_top_up',
            default => 'app.fiscal_source_unknown',
        };
    }

    private function status(mixed $status): string
    {
        $value = is_string($status) ? $status : '';

        return in_array($value, array_column(FiscalReceiptStatus::cases(), 'value'), true) ? $value : '';
    }

    private function safeProviderText(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $hidden = __('app.sensitive_value_hidden');
        $withoutEmails = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $hidden, $value) ?? $value;

        return preg_replace('/\+?\d[\d\s().\-]{7,}\d/u', $hidden, $withoutEmails) ?? $withoutEmails;
    }
}

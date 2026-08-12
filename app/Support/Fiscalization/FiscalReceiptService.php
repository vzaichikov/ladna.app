<?php

namespace App\Support\Fiscalization;

use App\Enums\AccountRole;
use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\EventOrderStatus;
use App\Enums\FestivalEditionPurchaseStatus;
use App\Enums\FestivalTicketOrderStatus;
use App\Enums\FiscalReceiptStatus;
use App\Enums\IntegrationScope;
use App\Enums\SmsTopUpPaymentStatus;
use App\Models\AccountSubscriptionPayment;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseRefund;
use App\Models\EventOrder;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalTicketOrder;
use App\Models\FiscalReceipt;
use App\Models\IntegrationSetting;
use App\Models\SmsTopUpPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class FiscalReceiptService
{
    public function __construct(
        private readonly FiscalizationAvailability $availability,
        private readonly CheckboxFiscalizationClient $checkbox,
    ) {}

    public function skipReasonFor(CustomerPurchase|CustomerPurchaseRefund|EventOrder|AccountSubscriptionPayment|SmsTopUpPayment|FestivalEditionPurchase|FestivalTicketOrder $payment): ?string
    {
        $payment->loadMissing('account');

        if ($payment->account?->isReadOnlyDemo()) {
            return 'read-only demo payments are not fiscalized';
        }

        if (! $this->paymentIsPaid($payment)) {
            return 'payment is not paid';
        }

        if ($payment->amount_cents <= 0) {
            return 'payment amount is zero';
        }

        if ($payment instanceof CustomerPurchaseRefund) {
            $payment->loadMissing('customerPurchase.fiscalReceipt');

            if (! $payment->customerPurchase?->fiscalReceipt?->isFiscalized()) {
                return 'source payment is not fiscalized';
            }
        }

        if ($payment instanceof CustomerPurchase && $payment->isManualCashStudioPayment()) {
            return 'manual studio cash payments are not fiscalized';
        }

        if (! $this->availability->methodForPayment($payment)) {
            return 'fiscalization is disabled or no fiscal method is configured';
        }

        return null;
    }

    public function fiscalizeCustomerPurchase(CustomerPurchase $purchase): ?FiscalReceipt
    {
        return $this->fiscalizePayment($purchase);
    }

    public function fiscalizeCustomerPurchaseRefund(CustomerPurchaseRefund $refund): ?FiscalReceipt
    {
        return $this->fiscalizePayment($refund);
    }

    public function fiscalizeAccountSubscriptionPayment(AccountSubscriptionPayment $payment): ?FiscalReceipt
    {
        return $this->fiscalizePayment($payment);
    }

    public function fiscalizeSmsTopUpPayment(SmsTopUpPayment $payment): ?FiscalReceipt
    {
        return $this->fiscalizePayment($payment);
    }

    public function fiscalizeFestivalEditionPurchase(FestivalEditionPurchase $purchase): ?FiscalReceipt
    {
        return $this->fiscalizePayment($purchase);
    }

    public function fiscalizeEventOrder(EventOrder $order): ?FiscalReceipt
    {
        return $this->fiscalizePayment($order);
    }

    public function fiscalizeFestivalTicketOrder(FestivalTicketOrder $order): ?FiscalReceipt
    {
        return $this->fiscalizePayment($order);
    }

    public function fiscalizePayment(CustomerPurchase|CustomerPurchaseRefund|EventOrder|AccountSubscriptionPayment|SmsTopUpPayment|FestivalEditionPurchase|FestivalTicketOrder $payment): ?FiscalReceipt
    {
        $receipt = null;

        try {
            $setting = $this->availability->methodForPayment($payment);

            if ($this->skipReasonFor($payment) !== null || ! $setting) {
                return null;
            }

            $receipt = $this->receiptFor($payment, $setting);

            if ($receipt->isFiscalized()) {
                return $receipt;
            }

            if ($receipt->status === FiscalReceiptStatus::Processing && filled($receipt->provider_receipt_id)) {
                $receipt = $this->applyResult(
                    $receipt,
                    $this->checkbox->status($setting, (string) $receipt->provider_receipt_id),
                );

                if ($receipt->status !== FiscalReceiptStatus::Failed) {
                    return $receipt;
                }
            }

            $receipt = $this->markSending($receipt, $payment);

            return $this->applyResult($receipt, $this->checkbox->sell($setting, $receipt->request_payload ?? []));
        } catch (Throwable $exception) {
            report($exception);

            return $receipt
                ? $this->markFailed($receipt, $exception->getMessage())
                : null;
        }
    }

    private function paymentIsPaid(CustomerPurchase|CustomerPurchaseRefund|EventOrder|AccountSubscriptionPayment|SmsTopUpPayment|FestivalEditionPurchase|FestivalTicketOrder $payment): bool
    {
        return match (true) {
            $payment instanceof CustomerPurchase => $payment->status === CustomerPurchaseStatus::PaymentPaid,
            $payment instanceof CustomerPurchaseRefund => $payment->exists,
            $payment instanceof EventOrder => in_array($payment->status, [EventOrderStatus::Paid, EventOrderStatus::RefundRequired], true),
            $payment instanceof FestivalTicketOrder => $payment->status === FestivalTicketOrderStatus::Paid,
            $payment instanceof AccountSubscriptionPayment => $payment->status === AccountSubscriptionPaymentStatus::PaymentPaid,
            $payment instanceof SmsTopUpPayment => $payment->status === SmsTopUpPaymentStatus::PaymentPaid,
            $payment instanceof FestivalEditionPurchase => in_array($payment->status, [FestivalEditionPurchaseStatus::Available, FestivalEditionPurchaseStatus::Redeemed], true),
        };
    }

    private function receiptFor(CustomerPurchase|CustomerPurchaseRefund|EventOrder|AccountSubscriptionPayment|SmsTopUpPayment|FestivalEditionPurchase|FestivalTicketOrder $payment, IntegrationSetting $setting): FiscalReceipt
    {
        return DB::transaction(function () use ($payment, $setting): FiscalReceipt {
            $receipt = FiscalReceipt::query()
                ->where('payment_type', $payment->getMorphClass())
                ->where('payment_id', $payment->getKey())
                ->where('provider', $setting->provider->value)
                ->lockForUpdate()
                ->first();

            if ($receipt) {
                return $receipt;
            }

            $accountId = $this->paymentAccountId($payment);
            $receipt = new FiscalReceipt([
                'account_id' => $accountId,
                'scope_type' => $payment instanceof AccountSubscriptionPayment || $payment instanceof SmsTopUpPayment || $payment instanceof FestivalEditionPurchase
                    ? IntegrationScope::Platform->value
                    : IntegrationScope::Account->value,
                'scope_id' => $payment instanceof AccountSubscriptionPayment || $payment instanceof SmsTopUpPayment || $payment instanceof FestivalEditionPurchase ? 0 : (int) $accountId,
                'provider' => $setting->provider->value,
                'status' => FiscalReceiptStatus::Pending->value,
            ]);
            $receipt->payment()->associate($payment);
            $receipt->save();

            return $receipt;
        });
    }

    private function markSending(FiscalReceipt $receipt, CustomerPurchase|CustomerPurchaseRefund|EventOrder|AccountSubscriptionPayment|SmsTopUpPayment|FestivalEditionPurchase|FestivalTicketOrder $payment): FiscalReceipt
    {
        $externalUuid = (string) Str::uuid();

        $receipt->forceFill([
            'external_uuid' => $externalUuid,
            'provider_receipt_id' => null,
            'provider_status' => null,
            'status' => FiscalReceiptStatus::Processing,
            'fiscal_number' => null,
            'attempts' => $receipt->attempts + 1,
            'request_payload' => $this->payloadFor($payment, $externalUuid),
            'response_payload' => null,
            'last_error' => null,
            'sent_at' => now(),
            'fiscalized_at' => null,
            'failed_at' => null,
        ])->save();

        return $receipt->refresh();
    }

    private function applyResult(FiscalReceipt $receipt, FiscalizationResult $result): FiscalReceipt
    {
        $receipt->forceFill([
            'status' => $result->status,
            'provider_receipt_id' => $result->providerReceiptId ?? $receipt->provider_receipt_id,
            'provider_status' => $result->providerStatus ?? $receipt->provider_status,
            'fiscal_number' => $result->fiscalNumber ?? $receipt->fiscal_number,
            'response_payload' => $result->payload,
            'last_error' => $result->error,
            'fiscalized_at' => $result->status === FiscalReceiptStatus::Fiscalized ? now() : $receipt->fiscalized_at,
            'failed_at' => $result->status === FiscalReceiptStatus::Failed ? now() : null,
        ])->save();

        return $receipt->refresh();
    }

    private function markFailed(FiscalReceipt $receipt, string $error): FiscalReceipt
    {
        $receipt->forceFill([
            'status' => FiscalReceiptStatus::Failed,
            'last_error' => $error,
            'failed_at' => now(),
        ])->save();

        return $receipt->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(CustomerPurchase|CustomerPurchaseRefund|EventOrder|AccountSubscriptionPayment|SmsTopUpPayment|FestivalEditionPurchase|FestivalTicketOrder $payment, string $externalUuid): array
    {
        $name = $this->itemName($payment);
        $isReturn = $payment instanceof CustomerPurchaseRefund;
        $goods = $payment instanceof FestivalTicketOrder
            ? $this->festivalTicketGoods($payment)
            : [[
                'good' => [
                    'code' => $this->paymentReference($payment),
                    'name' => $name,
                    'price' => $payment->amount_cents,
                ],
                'quantity' => 1000,
                'is_return' => $isReturn,
            ]];
        $payload = [
            'id' => $externalUuid,
            'goods' => $goods,
            'payments' => [[
                'type' => $payment instanceof CustomerPurchaseRefund && $payment->isCash()
                    ? 'CASH'
                    : 'CASHLESS',
                'value' => $payment->amount_cents,
                'label' => $payment instanceof CustomerPurchaseRefund
                    ? __('app.payment_refund_method_'.$payment->method)
                    : $this->paymentProviderLabel((string) $payment->provider),
            ]],
            'total_sum' => $payment->amount_cents,
        ];

        $delivery = $this->deliveryFor($payment);

        if ($delivery !== []) {
            $payload['delivery'] = $delivery;
        }

        return $payload;
    }

    private function itemName(CustomerPurchase|CustomerPurchaseRefund|EventOrder|AccountSubscriptionPayment|SmsTopUpPayment|FestivalEditionPurchase|FestivalTicketOrder $payment): string
    {
        if ($payment instanceof CustomerPurchaseRefund) {
            $payment->loadMissing('customerPurchase');

            return Str::limit($payment->customerPurchase?->plan_name ?: __('app.payment_refund'), 128, '');
        }

        if ($payment instanceof CustomerPurchase) {
            return Str::limit($payment->plan_name, 128, '');
        }

        if ($payment instanceof EventOrder) {
            $payment->loadMissing('event');

            return Str::limit($payment->event?->title ?: __('app.events'), 128, '');
        }

        if ($payment instanceof FestivalTicketOrder) {
            $payment->loadMissing('edition');

            return Str::limit($payment->edition?->title ?: __('app.festivals'), 128, '');
        }

        if ($payment instanceof SmsTopUpPayment) {
            return Str::limit(__('app.sms_top_up_receipt_item'), 128, '');
        }

        if ($payment instanceof FestivalEditionPurchase) {
            $payment->load('package');

            return Str::limit('Ladna Festival · '.$payment->package->name, 128, '');
        }

        $payment->loadMissing('plan');
        $planName = $payment->plan_name_snapshot ?: $payment->plan?->name ?: __('app.subscription_plan');
        $locations = $payment->billable_location_count
            ? ' · '.$payment->billable_location_count.' loc.'
            : '';

        return Str::limit('Ladna: '.$planName.$locations, 128, '');
    }

    /**
     * @return array<string, string>
     */
    private function deliveryFor(CustomerPurchase|CustomerPurchaseRefund|EventOrder|AccountSubscriptionPayment|SmsTopUpPayment|FestivalEditionPurchase|FestivalTicketOrder $payment): array
    {
        if ($payment instanceof CustomerPurchaseRefund) {
            $payment->loadMissing('customerPurchase.customer');
            $payment = $payment->customerPurchase;

            if (! $payment) {
                return [];
            }
        }

        if ($payment instanceof EventOrder || $payment instanceof FestivalTicketOrder) {
            return array_filter([
                'email' => $payment->buyer_email,
                'phone' => $payment->buyer_phone,
            ]);
        }

        if ($payment instanceof AccountSubscriptionPayment || $payment instanceof SmsTopUpPayment || $payment instanceof FestivalEditionPurchase) {
            $payment->loadMissing('account');

            if (! $payment->account) {
                return [];
            }

            $ownerEmail = $payment->account->users()
                ->wherePivot('role', AccountRole::Owner->value)
                ->whereNotNull('email')
                ->orderBy('account_memberships.id')
                ->pluck('email')
                ->first(fn (string $email): bool => filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false);

            return $ownerEmail ? ['email' => trim($ownerEmail)] : [];
        }

        if (! $payment instanceof CustomerPurchase) {
            return [];
        }

        $payment->loadMissing('customer');
        $delivery = [];

        if (filled($payment->customer?->email)) {
            $delivery['email'] = (string) $payment->customer->email;
        }

        if (filled($payment->customer?->phone)) {
            $delivery['phone'] = (string) $payment->customer->phone;
        }

        return $delivery;
    }

    private function paymentProviderLabel(string $provider): string
    {
        $label = config('integrations.providers.'.$provider.'.label');

        return is_string($label) ? $label : $provider;
    }

    private function paymentAccountId(CustomerPurchase|CustomerPurchaseRefund|EventOrder|AccountSubscriptionPayment|SmsTopUpPayment|FestivalEditionPurchase|FestivalTicketOrder $payment): ?int
    {
        return $payment->account_id ? (int) $payment->account_id : null;
    }

    private function paymentReference(CustomerPurchase|CustomerPurchaseRefund|EventOrder|AccountSubscriptionPayment|SmsTopUpPayment|FestivalEditionPurchase|FestivalTicketOrder $payment): string
    {
        if ($payment instanceof CustomerPurchaseRefund) {
            $payment->loadMissing('customerPurchase');

            return (string) ($payment->customerPurchase?->order_id ?? 'refund-'.$payment->id);
        }

        return $payment->order_id;
    }

    /**
     * @return array<int, array{good: array{code: string, name: string, price: int}, quantity: int, is_return: false}>
     */
    private function festivalTicketGoods(FestivalTicketOrder $order): array
    {
        $order->loadMissing('items');

        return $order->items->map(fn ($item): array => [
            'good' => [
                'code' => $order->order_id.'-'.$item->id,
                'name' => Str::limit($item->admission_name, 128, ''),
                'price' => $item->unit_price_cents,
            ],
            'quantity' => $item->quantity * 1000,
            'is_return' => false,
        ])->values()->all();
    }
}

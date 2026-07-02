<?php

namespace App\Support\Fiscalization;

use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\FiscalReceiptStatus;
use App\Enums\IntegrationScope;
use App\Models\AccountSubscriptionPayment;
use App\Models\CustomerPurchase;
use App\Models\FiscalReceipt;
use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class FiscalReceiptService
{
    public function __construct(
        private readonly FiscalizationAvailability $availability,
        private readonly CheckboxFiscalizationClient $checkbox,
    ) {}

    public function skipReasonFor(CustomerPurchase|AccountSubscriptionPayment $payment): ?string
    {
        if (! $this->paymentIsPaid($payment)) {
            return 'payment is not paid';
        }

        if ($payment->amount_cents <= 0) {
            return 'payment amount is zero';
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

    public function fiscalizeAccountSubscriptionPayment(AccountSubscriptionPayment $payment): ?FiscalReceipt
    {
        return $this->fiscalizePayment($payment);
    }

    public function fiscalizePayment(CustomerPurchase|AccountSubscriptionPayment $payment): ?FiscalReceipt
    {
        $setting = $this->availability->methodForPayment($payment);

        if ($this->skipReasonFor($payment) !== null || ! $setting) {
            return null;
        }

        $receipt = $this->receiptFor($payment, $setting);

        if ($receipt->isFiscalized()) {
            return $receipt;
        }

        try {
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

            return $this->markFailed($receipt, $exception->getMessage());
        }
    }

    private function paymentIsPaid(CustomerPurchase|AccountSubscriptionPayment $payment): bool
    {
        return match (true) {
            $payment instanceof CustomerPurchase => $payment->status === CustomerPurchaseStatus::PaymentPaid,
            $payment instanceof AccountSubscriptionPayment => $payment->status === AccountSubscriptionPaymentStatus::PaymentPaid,
        };
    }

    private function receiptFor(CustomerPurchase|AccountSubscriptionPayment $payment, IntegrationSetting $setting): FiscalReceipt
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
                'scope_type' => $payment instanceof CustomerPurchase
                    ? IntegrationScope::Account->value
                    : IntegrationScope::Platform->value,
                'scope_id' => $payment instanceof CustomerPurchase ? (int) $accountId : 0,
                'provider' => $setting->provider->value,
                'status' => FiscalReceiptStatus::Pending->value,
            ]);
            $receipt->payment()->associate($payment);
            $receipt->save();

            return $receipt;
        });
    }

    private function markSending(FiscalReceipt $receipt, CustomerPurchase|AccountSubscriptionPayment $payment): FiscalReceipt
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
    private function payloadFor(CustomerPurchase|AccountSubscriptionPayment $payment, string $externalUuid): array
    {
        $name = $this->itemName($payment);
        $payload = [
            'id' => $externalUuid,
            'goods' => [[
                'good' => [
                    'code' => $payment->order_id,
                    'name' => $name,
                    'price' => $payment->amount_cents,
                ],
                'quantity' => 1000,
                'is_return' => false,
            ]],
            'payments' => [[
                'type' => 'CASHLESS',
                'value' => $payment->amount_cents,
                'label' => $this->paymentProviderLabel($payment->provider),
            ]],
            'total_sum' => $payment->amount_cents,
        ];

        $delivery = $this->deliveryFor($payment);

        if ($delivery !== []) {
            $payload['delivery'] = $delivery;
        }

        return $payload;
    }

    private function itemName(CustomerPurchase|AccountSubscriptionPayment $payment): string
    {
        if ($payment instanceof CustomerPurchase) {
            return Str::limit($payment->plan_name, 128, '');
        }

        $payment->loadMissing('plan');

        return Str::limit('Ladna: '.($payment->plan?->name ?? __('app.subscription_plan')), 128, '');
    }

    /**
     * @return array<string, string>
     */
    private function deliveryFor(CustomerPurchase|AccountSubscriptionPayment $payment): array
    {
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

    private function paymentAccountId(CustomerPurchase|AccountSubscriptionPayment $payment): ?int
    {
        return $payment->account_id ? (int) $payment->account_id : null;
    }
}

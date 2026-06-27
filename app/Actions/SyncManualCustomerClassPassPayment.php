<?php

namespace App\Actions;

use App\Enums\CustomerPurchaseStatus;
use App\Models\Account;
use App\Models\CustomerClassPass;
use App\Models\CustomerPurchase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SyncManualCustomerClassPassPayment
{
    public function execute(Account $account, CustomerClassPass $customerClassPass, bool $isPaid): void
    {
        if ($customerClassPass->account_id !== $account->id) {
            abort(404);
        }

        if ($isPaid) {
            $this->markPaid($customerClassPass);

            return;
        }

        $this->markUnpaid($customerClassPass);
    }

    private function markPaid(CustomerClassPass $customerClassPass): void
    {
        if (! $customerClassPass->issued_location_id) {
            throw ValidationException::withMessages([
                'issued_location_id' => __('app.class_pass_payment_location_required'),
            ]);
        }

        $payment = $this->manualCashPayment($customerClassPass);
        $attributes = [
            'account_id' => $customerClassPass->account_id,
            'customer_id' => $customerClassPass->customer_id,
            'location_id' => $customerClassPass->issued_location_id,
            'class_pass_plan_id' => $customerClassPass->class_pass_plan_id,
            'customer_class_pass_id' => $customerClassPass->id,
            'provider' => CustomerPurchase::ProviderStudioCash,
            'payment_source' => CustomerPurchase::SourceManualCashClassPass,
            'status' => CustomerPurchaseStatus::PaymentPaid->value,
            'plan_name' => $customerClassPass->plan_name,
            'plan_slug' => $customerClassPass->plan_slug,
            'schedule_kind' => $customerClassPass->classPassPlan?->schedule_kind?->value ?? 'group_class',
            'amount_cents' => $customerClassPass->price_cents,
            'currency' => $customerClassPass->currency,
            'sessions_count' => $customerClassPass->sessions_count,
            'validity_days' => $customerClassPass->validity_days,
            'total_validity_days' => $customerClassPass->total_validity_days,
            'gateway_invoice_id' => null,
            'gateway_payment_id' => null,
            'gateway_status' => null,
            'gateway_checkout_payload' => null,
            'last_callback_payload' => null,
            'failure_reason' => null,
            'started_at' => $payment?->started_at ?? $customerClassPass->purchased_at ?? now(),
            'paid_at' => $payment?->paid_at ?? now(),
            'failed_at' => null,
            'expires_at' => null,
        ];

        if ($payment) {
            $payment->forceFill($attributes)->save();

            return;
        }

        CustomerPurchase::query()->create([
            ...$attributes,
            'order_id' => $this->orderId(),
        ]);
    }

    private function markUnpaid(CustomerClassPass $customerClassPass): void
    {
        $this->manualCashPayment($customerClassPass)?->delete();
    }

    private function manualCashPayment(CustomerClassPass $customerClassPass): ?CustomerPurchase
    {
        return CustomerPurchase::query()
            ->where('customer_class_pass_id', $customerClassPass->id)
            ->where('payment_source', CustomerPurchase::SourceManualCashClassPass)
            ->first();
    }

    private function orderId(): string
    {
        do {
            $orderId = 'CASH-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
        } while (CustomerPurchase::query()->where('order_id', $orderId)->exists());

        return $orderId;
    }
}

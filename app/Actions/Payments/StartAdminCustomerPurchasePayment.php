<?php

namespace App\Actions\Payments;

use App\Enums\CustomerPurchaseStatus;
use App\Models\CustomerPurchase;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Support\Facades\DB;
use Throwable;

class StartAdminCustomerPurchasePayment
{
    public function __construct(private readonly PaymentGatewayRegistry $gateways, private readonly StartCustomerPurchasePayment $startPayment) {}

    public function execute(CustomerPurchase $purchase): CustomerPurchase
    {
        $start = DB::transaction(function () use ($purchase): bool {
            $locked = CustomerPurchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if (! $locked->paymentWindowIsOpen() || $locked->gateway_start_requested_at !== null) {
                return false;
            }
            $locked->forceFill(['gateway_start_requested_at' => now()])->save();

            return true;
        });
        if (! $start) {
            return $purchase->refresh();
        }
        $purchase->loadMissing('account');
        $setting = $this->gateways->availableSettingsFor($purchase->account)->first(fn ($setting): bool => $setting->provider->value === $purchase->provider);
        if (! $setting) {
            $purchase->forceFill(['status' => 'payment_failed', 'failed_at' => now(), 'failure_reason' => __('app.payment_provider_unavailable')])->save();

            return $purchase->refresh();
        }
        try {
            $checkout = $this->startPayment->execute($purchase, $setting, route('public.customer-cart.payment', [$purchase->account->slug, $purchase->access_token_encrypted]));
            $purchase->forceFill(['gateway_checkout_payload' => [
                ...($purchase->gateway_checkout_payload ?? []),
                'cart_checkout' => ['type' => $checkout->type, 'url' => $checkout->url, 'method' => $checkout->method, 'fields' => $checkout->fields],
            ]])->save();
        } catch (Throwable $exception) {
            report($exception);
            $purchase->refresh();
            CustomerPurchase::query()->whereKey($purchase->id)
                ->whereIn('status', [CustomerPurchaseStatus::PaymentStarted->value, CustomerPurchaseStatus::PaymentPending->value])
                ->update(['status' => CustomerPurchaseStatus::PaymentPending->value, 'failure_reason' => __('app.customer_cart_gateway_wait')]);
        }

        return $purchase->refresh();
    }
}

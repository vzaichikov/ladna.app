<?php

namespace App\Actions\Payments;

use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\Location;
use App\Models\User;
use App\Support\ActorSnapshot;
use App\Support\Payments\CustomerCartQuote;
use App\Support\Payments\PaymentGatewayRegistry;
use App\Support\Promotions\PromotionCodeNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateAdminCustomerPurchase
{
    public function __construct(
        private readonly CustomerCartQuote $quotes,
        private readonly PaymentGatewayRegistry $gateways,
        private readonly PromotionCodeNormalizer $codes,
        private readonly ActorSnapshot $actors,
    ) {}

    /** @param array<string, mixed> $input */
    public function execute(Account $account, Customer $customer, User $actor, array $input): CustomerPurchase
    {
        abort_unless($customer->account_id === $account->id, 404);
        $quantities = $this->quotes->quantities($input['items']);
        $code = $this->codes->normalize($input['promo_code'] ?? '');
        $method = $input['payment_method'];
        $fingerprint = hash('sha256', json_encode([
            'customer' => $customer->id, 'quantities' => $quantities, 'location' => (int) $input['location_id'],
            'code' => $code, 'method' => $method, 'provider' => $method === CustomerPurchase::PaymentMethodOnline ? ($input['provider'] ?? null) : null,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($account, $customer, $actor, $input, $method, $fingerprint, $code): CustomerPurchase {
            Customer::query()->whereBelongsTo($account)->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $orderId = 'CART-'.$input['idempotency_key'];
            $existing = $account->customerPurchases()->where('order_id', $orderId)->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals((string) $existing->checkout_fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages(['idempotency_key' => __('app.customer_cart_idempotency_conflict')]);
                }

                return $existing;
            }
            $location = $account->locations()->active()->whereKey($input['location_id'])->first();
            if (! $location instanceof Location) {
                throw ValidationException::withMessages(['location_id' => __('app.class_pass_payment_location_required')]);
            }
            $quote = $this->quotes->execute($account, $customer, $input['items'], $code, true);
            if (! hash_equals($quote['quote_hash'], $input['quote_hash'])) {
                throw ValidationException::withMessages(['quote_hash' => __('app.customer_cart_quote_changed')]);
            }
            if ($quote['requires_payment'] && $method !== CustomerPurchase::PaymentMethodOnline && ! filter_var($input['payment_received'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                throw ValidationException::withMessages(['payment_received' => __('app.customer_cart_receipt_required')]);
            }
            $provider = match ($method) {
                CustomerPurchase::PaymentMethodCash => CustomerPurchase::ProviderStudioCash,
                CustomerPurchase::PaymentMethodCardTransfer => CustomerPurchase::ProviderStudioCardTransfer,
                default => (string) ($input['provider'] ?? ''),
            };
            if ($quote['requires_payment'] && $method === CustomerPurchase::PaymentMethodOnline
                && ! $this->gateways->availableSettingsFor($account)->contains(fn ($setting): bool => $setting->provider->value === $provider)) {
                throw ValidationException::withMessages(['provider' => __('app.payment_provider_unavailable')]);
            }
            if (! $quote['requires_payment']) {
                $provider = CustomerPurchase::ProviderFree;
            }
            $promotion = $quote['promotion'];
            $promo = $promotion ? $promotion['promoCode'] : null;
            $token = Str::random(64);
            $startedAt = now();
            $purchase = $account->customerPurchases()->create([
                'customer_id' => $customer->id, 'location_id' => $location->id,
                'provider' => $provider,
                'payment_source' => match ($method) {
                    CustomerPurchase::PaymentMethodCash => CustomerPurchase::SourceManualCashClassPass,
                    CustomerPurchase::PaymentMethodCardTransfer => CustomerPurchase::SourceManualCardClassPass,
                    default => CustomerPurchase::SourceOnlineCheckout,
                },
                'order_id' => $orderId, 'status' => 'payment_started',
                'plan_name' => __('app.customer_cart_purchase', ['count' => count($quote['items'])]),
                'schedule_kind' => null, 'sessions_count' => null, 'validity_days' => null, 'total_validity_days' => null,
                'subtotal_cents' => $quote['subtotal_cents'], 'discount_cents' => $quote['discount_cents'],
                'amount_cents' => $quote['total_cents'], 'currency' => $quote['currency'],
                'studio_promo_code_id' => $promo?->id, 'promo_name' => $promo?->name, 'promo_code' => $promo?->code,
                'promo_discount_type' => $promo?->discount_type?->value, 'promo_discount_value' => $promo?->discount_value,
                'promo_buy_quantity' => $promo?->buy_quantity, 'promo_free_quantity' => $promo?->free_quantity,
                'promo_email_hash' => $promotion['emailHash'] ?? null, 'promo_phone_hash' => $promotion['phoneHash'] ?? null,
                'checkout_fingerprint' => $fingerprint, 'access_token_hash' => hash('sha256', $token), 'access_token_encrypted' => $token,
                'started_at' => $startedAt, 'expires_at' => $method === CustomerPurchase::PaymentMethodOnline && $quote['requires_payment'] ? $startedAt->copy()->addHour() : null,
                'trial_eligibility_validated_at' => collect($quote['items'])->contains('is_trial', true) ? $startedAt : null,
                ...$this->actors->capture($account, $actor),
            ]);
            foreach ($quote['items'] as $item) {
                $purchase->items()->create(['account_id' => $account->id, ...$item]);
            }

            return $purchase->load('items');
        }, attempts: 5);
    }
}

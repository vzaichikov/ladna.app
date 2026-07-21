<?php

namespace Database\Factories;

use App\Enums\SubscriptionPaymentMethodStatus;
use App\Models\AccountSubscription;
use App\Models\AccountSubscriptionPaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccountSubscriptionPaymentMethod>
 */
class AccountSubscriptionPaymentMethodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_subscription_id' => AccountSubscription::factory(),
            'account_id' => fn (array $attributes): int => AccountSubscription::query()
                ->findOrFail($attributes['account_subscription_id'])
                ->account_id,
            'provider' => 'monopay',
            'provider_wallet_id' => (string) Str::uuid(),
            'provider_card_token' => null,
            'masked_pan' => null,
            'status' => SubscriptionPaymentMethodStatus::PendingVerification->value,
            'verification_reference' => 'SAAS-VERIFY-'.Str::upper(Str::random(20)),
        ];
    }
}

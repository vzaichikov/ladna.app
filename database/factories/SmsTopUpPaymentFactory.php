<?php

namespace Database\Factories;

use App\Enums\SmsTopUpKind;
use App\Enums\SmsTopUpPaymentStatus;
use App\Models\AccountSmsWallet;
use App\Models\SmsTopUpPayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SmsTopUpPayment>
 */
class SmsTopUpPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_sms_wallet_id' => AccountSmsWallet::factory(),
            'account_id' => fn (array $attributes): int => AccountSmsWallet::query()
                ->findOrFail($attributes['account_sms_wallet_id'])
                ->account_id,
            'provider' => 'monopay',
            'kind' => SmsTopUpKind::Manual->value,
            'order_id' => 'SMS-TOPUP-'.Str::upper(Str::random(20)),
            'status' => SmsTopUpPaymentStatus::PaymentStarted->value,
            'amount_cents' => fake()->randomElement([5_000, 10_000, 20_000]),
            'currency' => 'UAH',
            'idempotency_key' => 'SMS-TOPUP-IDEMP-'.Str::upper(Str::random(20)),
            'started_at' => now(),
        ];
    }
}

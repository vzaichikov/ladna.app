<?php

namespace Database\Factories;

use App\Enums\SmsDeliveryPurpose;
use App\Enums\SmsDeliveryStatus;
use App\Enums\SmsSendingMode;
use App\Models\AccountSmsWallet;
use App\Models\SmsDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SmsDelivery>
 */
class SmsDeliveryFactory extends Factory
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
            'purpose' => SmsDeliveryPurpose::CustomerNotification->value,
            'source_mode' => SmsSendingMode::LadnaService->value,
            'provider' => 'smsclub',
            'status' => SmsDeliveryStatus::Pending->value,
            'recipient_phone' => '+380'.fake()->numerify('#########'),
            'message_preview' => fake()->sentence(),
            'idempotency_key' => 'SMS-DELIVERY-'.Str::upper(Str::random(24)),
            'estimated_segments' => 1,
            'reserved_amount_cents' => 140,
            'currency' => 'UAH',
        ];
    }
}

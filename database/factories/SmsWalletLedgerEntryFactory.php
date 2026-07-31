<?php

namespace Database\Factories;

use App\Enums\SmsWalletLedgerEntryType;
use App\Models\AccountSmsWallet;
use App\Models\SmsWalletLedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SmsWalletLedgerEntry>
 */
class SmsWalletLedgerEntryFactory extends Factory
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
            'type' => SmsWalletLedgerEntryType::ManualAdjustment->value,
            'amount_cents' => fake()->numberBetween(-5_000, 20_000),
            'balance_after_cents' => fake()->numberBetween(0, 50_000),
            'outstanding_after_cents' => 0,
            'reason' => fake()->sentence(),
            'idempotency_key' => 'SMS-LEDGER-'.Str::upper(Str::random(24)),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountSmsWallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountSmsWallet>
 */
class AccountSmsWalletFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'balance_cents' => 0,
            'reserved_cents' => 0,
            'outstanding_cents' => 0,
            'currency' => 'UAH',
            'auto_top_up_enabled' => false,
            'auto_top_up_monthly_spent_cents' => 0,
        ];
    }
}

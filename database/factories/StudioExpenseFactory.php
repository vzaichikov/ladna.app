<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\ExpenseCategory;
use App\Models\StudioExpense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudioExpense>
 */
class StudioExpenseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $account = Account::factory();

        return [
            'account_id' => $account,
            'expense_category_id' => ExpenseCategory::factory()->for($account),
            'location_id' => null,
            'amount_cents' => fake()->numberBetween(100, 100000),
            'currency' => 'UAH',
            'payment_method' => StudioExpense::PaymentMethodBankCard,
            'occurred_at' => now(),
            'actor_name' => fake()->name(),
            'actor_email' => fake()->safeEmail(),
            'actor_role' => 'owner',
            'reason' => fake()->sentence(),
        ];
    }
}

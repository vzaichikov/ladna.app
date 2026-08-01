<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\FinanceEpoch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceEpoch>
 */
class FinanceEpochFactory extends Factory
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
            'starts_at' => now(),
            'is_legacy' => false,
            'reason' => fake()->sentence(),
        ];
    }
}

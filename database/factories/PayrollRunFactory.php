<?php

namespace Database\Factories;

use App\Enums\PayrollCadence;
use App\Models\Account;
use App\Models\PayrollRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PayrollRun>
 */
class PayrollRunFactory extends Factory
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
            'cadence' => PayrollCadence::Monthly->value,
            'period_starts_on' => now()->startOfMonth()->toDateString(),
            'period_ends_on' => now()->endOfMonth()->toDateString(),
            'status' => PayrollRun::StatusClosed,
            'totals' => ['UAH' => 100000],
            'incomplete' => false,
            'idempotency_key' => (string) Str::uuid(),
            'closed_at' => now(),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Enums\ClassBookingStatus;
use App\Models\Account;
use App\Models\SalaryModel;
use App\Models\SalaryModelVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalaryModelVersion>
 */
class SalaryModelVersionFactory extends Factory
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
            'salary_model_id' => SalaryModel::factory()->for($account),
            'created_by_user_id' => null,
            'effective_from' => now()->startOfMonth()->toDateString(),
            'currency' => 'UAH',
            'period_unit' => null,
            'amount_cents' => null,
            'counted_booking_statuses' => [ClassBookingStatus::Attended->value],
            'pay_empty_classes' => false,
            'superseded_at' => null,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Models\Trainer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollRunLine>
 */
class PayrollRunLineFactory extends Factory
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
            'payroll_run_id' => PayrollRun::factory()->for($account),
            'trainer_id' => Trainer::factory()->for($account),
            'amounts' => ['UAH' => 100000],
            'model_names' => ['Standard'],
            'entries' => [],
            'incomplete' => false,
        ];
    }
}

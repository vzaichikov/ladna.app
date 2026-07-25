<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\SalaryModel;
use App\Models\SalaryModelClassRule;
use App\Models\SalaryModelClassRuleTier;
use App\Models\SalaryModelVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalaryModelClassRuleTier>
 */
class SalaryModelClassRuleTierFactory extends Factory
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
            'salary_model_class_rule_id' => SalaryModelClassRule::factory()
                ->for($account)
                ->for(
                    SalaryModelVersion::factory()
                        ->for($account)
                        ->for(SalaryModel::factory()->for($account), 'salaryModel'),
                    'version',
                ),
            'minimum_people' => 0,
            'maximum_people' => null,
            'amount_cents' => 50000,
        ];
    }
}

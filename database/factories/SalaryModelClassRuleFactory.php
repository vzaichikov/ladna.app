<?php

namespace Database\Factories;

use App\Enums\SalaryClassFormulaType;
use App\Models\Account;
use App\Models\SalaryModel;
use App\Models\SalaryModelClassRule;
use App\Models\SalaryModelVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalaryModelClassRule>
 */
class SalaryModelClassRuleFactory extends Factory
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
            'salary_model_version_id' => SalaryModelVersion::factory()
                ->for($account)
                ->for(SalaryModel::factory()->for($account), 'salaryModel'),
            'class_type_id' => null,
            'class_type_name' => null,
            'is_default' => true,
            'formula_type' => SalaryClassFormulaType::Flat->value,
            'flat_amount_cents' => 50000,
            'minimum_people' => 0,
            'included_people' => 0,
        ];
    }
}

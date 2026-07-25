<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\SalaryModel;
use App\Models\Trainer;
use App\Models\TrainerSalaryAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainerSalaryAssignment>
 */
class TrainerSalaryAssignmentFactory extends Factory
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
            'trainer_id' => Trainer::factory()->for($account),
            'salary_model_id' => SalaryModel::factory()->for($account),
            'created_by_user_id' => null,
            'effective_from' => now()->startOfMonth()->toDateString(),
            'superseded_at' => null,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Enums\SalaryModelType;
use App\Models\Account;
use App\Models\SalaryModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalaryModel>
 */
class SalaryModelFactory extends Factory
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
            'name' => fake()->words(3, true),
            'type' => SalaryModelType::PerClass->value,
            'archived_at' => null,
        ];
    }
}

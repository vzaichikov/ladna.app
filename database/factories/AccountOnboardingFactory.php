<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountOnboarding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountOnboarding>
 */
class AccountOnboardingFactory extends Factory
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
            'current_step' => 2,
            'answers' => [
                'steps' => [
                    1 => [
                        'studio_stage' => 'operating',
                        'location_count' => 1,
                    ],
                ],
            ],
            'completed_at' => null,
        ];
    }
}

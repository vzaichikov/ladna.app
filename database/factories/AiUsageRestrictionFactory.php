<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AiUsageRestriction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsageRestriction>
 */
class AiUsageRestrictionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'last_account_id' => Account::factory(),
            'consecutive_out_of_scope_count' => 0,
            'cooldown_level' => 0,
        ];
    }
}

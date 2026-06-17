<?php

namespace Database\Factories;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountMembership>
 */
class AccountMembershipFactory extends Factory
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
            'user_id' => User::factory(),
            'role' => AccountRole::Owner->value,
            'permissions' => null,
        ];
    }
}

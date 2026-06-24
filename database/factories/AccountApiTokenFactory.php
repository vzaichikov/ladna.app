<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountApiToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccountApiToken>
 */
class AccountApiTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = 'ladna_'.Str::random(48);

        return [
            'account_id' => Account::factory(),
            'name' => fake()->words(2, true),
            'token_hash' => hash('sha256', $token),
            'encrypted_token' => $token,
            'last_four' => substr($token, -4),
            'is_active' => true,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\McpOAuthConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Laravel\Passport\Client;

/**
 * @extends Factory<McpOAuthConnection>
 */
class McpOAuthConnectionFactory extends Factory
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
            'oauth_client_id' => Client::factory(),
            'client_name' => fake()->randomElement(['ChatGPT', 'Claude']),
            'last_used_at' => null,
            'revoked_at' => null,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountActivityLog>
 */
class AccountActivityLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::factory();

        return [
            'account_id' => Account::factory(),
            'action' => 'dashboard.accounts.customers.update',
            'route_name' => 'dashboard.accounts.customers.update',
            'method' => 'PUT',
            'status_code' => 302,
            'actor_user_id' => $user,
            'actor_trainer_id' => null,
            'actor_name' => fake()->name(),
            'actor_email' => fake()->safeEmail(),
            'actor_role' => 'owner',
            'subject_type' => 'App\\Models\\Customer',
            'subject_id' => fake()->numberBetween(1, 1000),
            'subject_label' => fake()->name(),
            'url' => fake()->url(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'occurred_at' => now(),
        ];
    }
}

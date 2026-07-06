<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Customer;
use App\Models\MobileSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MobileSession>
 */
class MobileSessionFactory extends Factory
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
            'customer_id' => null,
            'guard' => MobileSession::GuardStaff,
            'role' => 'owner',
            'token_hash' => hash('sha256', Str::random(80)),
            'last_four' => Str::upper(Str::random(4)),
            'device_name' => fake()->randomElement(['Android phone', 'iPhone']),
            'platform' => fake()->randomElement(['android', 'ios']),
            'expires_at' => now()->addDays(90),
            'last_used_at' => now(),
            'revoked_at' => null,
        ];
    }

    public function customer(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => null,
            'customer_id' => Customer::factory(),
            'guard' => MobileSession::GuardCustomer,
            'role' => 'customer',
        ]);
    }
}

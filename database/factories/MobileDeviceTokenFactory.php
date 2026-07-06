<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\MobileDeviceToken;
use App\Models\MobileSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MobileDeviceToken>
 */
class MobileDeviceTokenFactory extends Factory
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
            'mobile_session_id' => MobileSession::factory(),
            'user_id' => null,
            'customer_id' => null,
            'provider' => 'fcm',
            'platform' => fake()->randomElement(['android', 'ios']),
            'token_hash' => hash('sha256', Str::random(120)),
            'encrypted_token' => 'fcm_'.Str::random(120),
            'device_name' => fake()->randomElement(['Android phone', 'iPhone']),
            'app_version' => '0.1.0',
            'last_seen_at' => now(),
            'revoked_at' => null,
        ];
    }
}

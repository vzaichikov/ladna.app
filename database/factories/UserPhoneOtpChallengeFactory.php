<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserPhoneOtpChallenge;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<UserPhoneOtpChallenge>
 */
class UserPhoneOtpChallengeFactory extends Factory
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
            'phone' => '+380'.fake()->numerify('#########'),
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
            'consumed_at' => null,
            'resend_available_at' => now()->addMinute(),
            'attempts' => 0,
            'send_count' => 1,
            'last_sent_at' => now(),
            'provider' => 'turbosms',
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }
}

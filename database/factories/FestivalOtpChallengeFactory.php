<?php

namespace Database\Factories;

use App\Enums\FestivalPortalRole;
use App\Models\Account;
use App\Models\FestivalOtpChallenge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FestivalOtpChallenge>
 */
class FestivalOtpChallengeFactory extends Factory
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
            'role' => FestivalPortalRole::Registrant,
            'phone' => '+380'.fake()->numerify('#########'),
            'code_hash' => bcrypt('123456'),
            'expires_at' => now()->addMinutes(10),
            'resend_available_at' => now()->addMinute(),
            'attempts' => 0,
            'send_count' => 1,
            'last_sent_at' => now(),
        ];
    }
}

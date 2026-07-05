<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Location;
use App\Models\Room;
use App\Models\UnknownPresenceInterval;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnknownPresenceInterval>
 */
class UnknownPresenceIntervalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = now()->subMinutes(fake()->numberBetween(10, 120));
        $sampleCount = fake()->numberBetween(1, 6);

        return [
            'account_id' => Account::factory(),
            'location_id' => Location::factory(),
            'room_id' => Room::factory(),
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addMinutes(($sampleCount - 1) * 7),
            'sample_count' => $sampleCount,
            'peak_detected_count' => fake()->numberBetween(1, 12),
        ];
    }
}

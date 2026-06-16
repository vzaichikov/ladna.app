<?php

namespace Database\Factories;

use App\Enums\ScheduledClassStatus;
use App\Models\Account;
use App\Models\ClassType;
use App\Models\Instructor;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledClass>
 */
class ScheduledClassFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('+1 day', '+14 days');

        return [
            'account_id' => Account::factory(),
            'location_id' => Location::factory(),
            'room_id' => Room::factory(),
            'class_type_id' => ClassType::factory(),
            'instructor_id' => Instructor::factory(),
            'schedule_series_id' => null,
            'title' => fake()->randomElement(['Pole Beginner', 'Pole Choreo', 'Stretching', 'Exotic Flow', 'Strength & Conditioning']),
            'description' => fake()->sentence(),
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->modify('+1 hour'),
            'capacity' => fake()->numberBetween(8, 16),
            'booking_cutoff_minutes' => null,
            'is_generated' => false,
            'is_manually_modified' => false,
            'metadata' => null,
            'is_public' => true,
            'status' => ScheduledClassStatus::Scheduled->value,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Enums\ScheduleSeriesStatus;
use App\Models\Account;
use App\Models\ClassType;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduleSeries;
use App\Models\Trainer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleSeries>
 */
class ScheduleSeriesFactory extends Factory
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
            'location_id' => Location::factory(),
            'room_id' => Room::factory(),
            'class_type_id' => ClassType::factory(),
            'trainer_id' => Trainer::factory(),
            'title' => null,
            'description' => null,
            'weekday' => fake()->numberBetween(1, 7),
            'start_time' => fake()->randomElement(['10:00', '14:00', '18:00']),
            'start_date' => now()->toDateString(),
            'end_date' => null,
            'capacity' => null,
            'duration_minutes' => null,
            'booking_cutoff_minutes' => null,
            'cancellation_cutoff_minutes' => null,
            'status' => ScheduleSeriesStatus::Active->value,
        ];
    }
}

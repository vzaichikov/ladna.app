<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\ScheduledClassPeopleCount;
use App\Models\Trainer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledClassPeopleCount>
 */
class ScheduledClassPeopleCountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $attended = fake()->numberBetween(0, 12);
        $detected = fake()->numberBetween(0, 12);

        return [
            'account_id' => Account::factory(),
            'scheduled_class_id' => ScheduledClass::factory(),
            'location_id' => Location::factory(),
            'room_id' => Room::factory(),
            'trainer_id' => Trainer::factory(),
            'status' => $attended === $detected
                ? ScheduledClassPeopleCount::StatusMatched
                : ScheduledClassPeopleCount::StatusMismatch,
            'attended_count' => $attended,
            'detected_count' => $detected,
            'delta' => $detected - $attended,
            'successful_samples_count' => fake()->numberBetween(1, 8),
            'failed_samples_count' => fake()->numberBetween(0, 2),
            'first_sampled_at' => now()->subMinutes(50),
            'last_sampled_at' => now()->subMinutes(10),
            'summarized_at' => now(),
        ];
    }

    public function insufficientData(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ScheduledClassPeopleCount::StatusInsufficientData,
            'detected_count' => null,
            'delta' => null,
            'successful_samples_count' => 0,
        ]);
    }
}

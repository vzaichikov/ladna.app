<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Location;
use App\Models\PeopleCounterSample;
use App\Models\Room;
use App\Models\ScheduledClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PeopleCounterSample>
 */
class PeopleCounterSampleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $detectedCount = fake()->numberBetween(0, 12);

        return [
            'account_id' => Account::factory(),
            'scheduled_class_id' => ScheduledClass::factory(),
            'location_id' => Location::factory(),
            'room_id' => Room::factory(),
            'captured_at' => now(),
            'status' => PeopleCounterSample::StatusSucceeded,
            'failure_reason' => null,
            'original_image_path' => 'people-counter/testing/original.jpg',
            'masked_image_path' => 'people-counter/testing/masked.jpg',
            'image_width' => 1280,
            'image_height' => 720,
            'detected_count' => $detectedCount,
            'average_confidence' => fake()->randomFloat(4, 0.5, 0.99),
            'detections' => [],
            'response_payload' => ['count' => $detectedCount, 'detections' => []],
        ];
    }

    public function captureFailed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PeopleCounterSample::StatusCaptureFailed,
            'failure_reason' => 'Camera unavailable.',
            'original_image_path' => null,
            'masked_image_path' => null,
            'image_width' => null,
            'image_height' => null,
            'detected_count' => null,
            'average_confidence' => null,
            'detections' => null,
            'response_payload' => null,
        ]);
    }

    public function detectionFailed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PeopleCounterSample::StatusDetectionFailed,
            'failure_reason' => 'Detection unavailable.',
            'detected_count' => null,
            'average_confidence' => null,
            'detections' => null,
            'response_payload' => null,
        ]);
    }
}

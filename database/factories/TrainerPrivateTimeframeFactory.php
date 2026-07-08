<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Location;
use App\Models\Trainer;
use App\Models\TrainerPrivateTimeframe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainerPrivateTimeframe>
 */
class TrainerPrivateTimeframeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->addDays($this->faker->numberBetween(1, 14))
            ->setTime((int) $this->faker->numberBetween(8, 20), $this->faker->randomElement([0, 30]));

        return [
            'account_id' => Account::factory(),
            'trainer_id' => Trainer::factory(),
            'location_id' => Location::factory(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
        ];
    }
}

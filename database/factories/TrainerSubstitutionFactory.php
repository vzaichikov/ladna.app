<?php

namespace Database\Factories;

use App\Enums\TrainerSubstitutionMode;
use App\Models\Account;
use App\Models\Location;
use App\Models\Room;
use App\Models\Trainer;
use App\Models\TrainerSubstitution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainerSubstitution>
 */
class TrainerSubstitutionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = fake()->dateTimeBetween('now', '+2 weeks')->format('Y-m-d');

        return [
            'account_id' => Account::factory(),
            'replaced_trainer_id' => Trainer::factory(),
            'substitute_trainer_id' => Trainer::factory(),
            'location_id' => Location::factory(),
            'room_id' => Room::factory(),
            'mode' => TrainerSubstitutionMode::Period->value,
            'date_from' => $date,
            'date_to' => $date,
            'scheduled_class_ids' => null,
            'class_type_ids' => [],
            'replaced_trainer_name' => fake()->name(),
            'substitute_trainer_name' => fake()->name(),
            'location_name' => fake()->company(),
            'room_name' => fake()->word(),
        ];
    }
}

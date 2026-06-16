<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Location;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['Big Hall', 'Small Hall', 'Aerial Room', 'Private Room']);

        return [
            'account_id' => Account::factory(),
            'location_id' => Location::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'description' => fake()->sentence(),
            'capacity' => fake()->numberBetween(6, 16),
            'is_active' => true,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Location;
use App\Models\ServiceRoom;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ServiceRoom>
 */
class ServiceRoomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['Reception', 'Entrance', 'Lobby', 'Corridor']);

        return [
            'account_id' => Account::factory(),
            'location_id' => Location::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'description' => fake()->sentence(),
            'color' => null,
            'is_active' => true,
            'rtsp_url' => null,
            'rtsp_enabled' => false,
        ];
    }
}

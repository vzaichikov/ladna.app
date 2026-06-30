<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->city().' Studio';

        return [
            'account_id' => Account::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'address' => fake()->address(),
            'google_maps_embed_url' => null,
            'phone' => fake()->phoneNumber(),
            'email' => fake()->safeEmail(),
            'timezone' => 'Europe/Kyiv',
            'is_active' => true,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\ClassPassSegment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ClassPassSegment>
 */
class ClassPassSegmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['Kids passes', 'Morning passes', 'Day passes', 'Evening passes']);

        return [
            'account_id' => Account::factory(),
            'schedule_kind' => 'group_class',
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\FestivalStage;
use App\Models\FestivalTimeline;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FestivalTimeline>
 */
class FestivalTimelineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'festival_stage_id' => FestivalStage::factory(),
            'festival_edition_id' => fn (array $attributes): int => FestivalStage::query()->findOrFail($attributes['festival_stage_id'])->festival_edition_id,
            'account_id' => fn (array $attributes): int => FestivalStage::query()->findOrFail($attributes['festival_stage_id'])->account_id,
        ];
    }
}

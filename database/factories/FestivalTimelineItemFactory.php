<?php

namespace Database\Factories;

use App\Models\FestivalTimeline;
use App\Models\FestivalTimelineItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FestivalTimelineItem>
 */
class FestivalTimelineItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'festival_timeline_id' => FestivalTimeline::factory(),
            'festival_edition_id' => fn (array $attributes): int => FestivalTimeline::query()->findOrFail($attributes['festival_timeline_id'])->festival_edition_id,
            'account_id' => fn (array $attributes): int => FestivalTimeline::query()->findOrFail($attributes['festival_timeline_id'])->account_id,
            'label' => fake()->sentence(3),
            'type' => 'custom',
            'duration_seconds' => 300,
            'planned_starts_at' => now()->addHour(),
            'planned_ends_at' => now()->addHour()->addMinutes(5),
            'sort_order' => 10,
            'is_enabled' => true,
        ];
    }
}

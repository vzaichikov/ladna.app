<?php

namespace Database\Factories;

use App\Models\FestivalEdition;
use App\Models\FestivalOnlineStream;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FestivalOnlineStream>
 */
class FestivalOnlineStreamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $publisherToken = Str::random(64);

        return [
            'account_id' => fn (array $attributes) => FestivalEdition::query()->findOrFail($attributes['festival_edition_id'])->account_id,
            'festival_edition_id' => FestivalEdition::factory(),
            'is_enabled' => false,
            'path' => 'festival-'.Str::lower(Str::random(32)),
            'publisher_token_encrypted' => $publisherToken,
            'publisher_token_hash' => hash('sha256', $publisherToken),
            'opens_at' => now()->subHour(),
            'closes_at' => now()->addHours(4),
            'playback_override' => 'automatic',
        ];
    }

    public function enabled(): static
    {
        return $this->state(fn (): array => [
            'is_enabled' => true,
            'playback_override' => 'open',
        ]);
    }
}

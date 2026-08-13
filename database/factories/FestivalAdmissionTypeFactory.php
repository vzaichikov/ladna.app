<?php

namespace Database\Factories;

use App\Models\FestivalAdmissionType;
use App\Models\FestivalEdition;
use App\Models\FestivalOnlineStream;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FestivalAdmissionType> */
class FestivalAdmissionTypeFactory extends Factory
{
    protected $model = FestivalAdmissionType::class;

    public function definition(): array
    {
        return ['account_id' => fn (array $attributes) => FestivalEdition::findOrFail($attributes['festival_edition_id'])->account_id, 'festival_edition_id' => FestivalEdition::factory(), 'name' => 'General admission', 'inventory' => 100, 'price_cents' => 30000, 'max_per_order' => 10, 'is_active' => true];
    }

    public function online(FestivalOnlineStream $stream): static
    {
        return $this->state(fn (): array => [
            'account_id' => $stream->account_id,
            'festival_edition_id' => $stream->festival_edition_id,
            'delivery_mode' => 'online_stream',
            'festival_online_stream_id' => $stream->id,
            'max_per_order' => 1,
        ]);
    }
}

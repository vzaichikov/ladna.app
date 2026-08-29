<?php

namespace Database\Factories;

use App\Models\FestivalEntrancePass;
use App\Models\FestivalEntrancePassScan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FestivalEntrancePassScan> */
class FestivalEntrancePassScanFactory extends Factory
{
    protected $model = FestivalEntrancePassScan::class;

    public function definition(): array
    {
        return [
            'festival_entrance_pass_id' => FestivalEntrancePass::factory(),
            'account_id' => fn (array $attributes) => FestivalEntrancePass::findOrFail($attributes['festival_entrance_pass_id'])->account_id,
            'festival_edition_id' => fn (array $attributes) => FestivalEntrancePass::findOrFail($attributes['festival_entrance_pass_id'])->festival_edition_id,
            'action' => 'check_in',
            'source' => 'qr',
            'occurred_at' => now(),
        ];
    }
}

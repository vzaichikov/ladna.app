<?php

namespace Database\Factories;

use App\Models\FestivalEdition;
use App\Models\FestivalEntrancePass;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FestivalEntrancePass> */
class FestivalEntrancePassFactory extends Factory
{
    protected $model = FestivalEntrancePass::class;

    public function definition(): array
    {
        $token = Str::random(64);

        return [
            'festival_edition_id' => FestivalEdition::factory(),
            'account_id' => fn (array $attributes) => FestivalEdition::findOrFail($attributes['festival_edition_id'])->account_id,
            'festival_participant_id' => fn (array $attributes) => FestivalParticipant::factory()
                ->for(FestivalPortalUser::factory()->state(['account_id' => FestivalEdition::findOrFail($attributes['festival_edition_id'])->account_id]))
                ->create(['account_id' => FestivalEdition::findOrFail($attributes['festival_edition_id'])->account_id])
                ->id,
            'code' => 'FSP-'.Str::upper(Str::random(4)).'-'.Str::upper(Str::random(4)),
            'token_encrypted' => $token,
            'token_hash' => hash('sha256', $token),
            'status' => 'valid',
            'is_checked_in' => false,
        ];
    }
}

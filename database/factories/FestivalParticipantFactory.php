<?php

namespace Database\Factories;

use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FestivalParticipant> */
class FestivalParticipantFactory extends Factory
{
    protected $model = FestivalParticipant::class;

    public function definition(): array
    {
        return ['account_id' => fn (array $attributes) => FestivalPortalUser::findOrFail($attributes['festival_portal_user_id'])->account_id, 'festival_portal_user_id' => FestivalPortalUser::factory(), 'first_name' => fake()->firstName(), 'last_name' => fake()->lastName(), 'date_of_birth' => fake()->dateTimeBetween('-30 years', '-8 years')->format('Y-m-d')];
    }
}

<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\FestivalPortalUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FestivalPortalUser> */
class FestivalPortalUserFactory extends Factory
{
    protected $model = FestivalPortalUser::class;

    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return ['account_id' => Account::factory(), 'registrant_type' => 'coach', 'first_name' => fake()->firstName(), 'last_name' => fake()->lastName(), 'email' => $email, 'email_normalized' => mb_strtolower($email), 'phone' => fake()->e164PhoneNumber(), 'city' => fake()->city(), 'studio_name' => fake()->company(), 'locale' => 'uk', 'email_verified_at' => now()];
    }
}

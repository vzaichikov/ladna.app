<?php

namespace Database\Factories;

use App\Enums\FestivalPortalRole;
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

        $phone = fake()->unique()->e164PhoneNumber();

        return ['account_id' => Account::factory(), 'role' => FestivalPortalRole::Registrant, 'is_active' => true, 'registrant_type' => 'coach', 'first_name' => fake()->firstName(), 'last_name' => fake()->lastName(), 'email' => $email, 'email_normalized' => mb_strtolower($email), 'password' => 'secret', 'phone' => $phone, 'phone_normalized' => $phone, 'city' => fake()->city(), 'studio_name' => fake()->company(), 'locale' => 'uk', 'email_verified_at' => now()];
    }

    public function judge(): static
    {
        return $this->state(fn (): array => [
            'role' => FestivalPortalRole::Judge,
            'registrant_type' => null,
            'city' => null,
            'studio_name' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}

<?php

namespace Database\Factories;

use App\Models\AccountSignupRequest;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccountSignupRequest>
 */
class AccountSignupRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $studioName = fake()->company();

        return [
            'subscription_plan_id' => SubscriptionPlan::factory(),
            'status' => 'pending_payment',
            'provider' => 'monopay',
            'order_id' => 'DEMO-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8)),
            'studio_name' => $studioName,
            'account_slug' => Str::slug($studioName).'-'.fake()->unique()->numberBetween(1000, 9999),
            'owner_name' => fake()->name(),
            'owner_email' => fake()->unique()->safeEmail(),
            'owner_phone' => '+38050'.fake()->numerify('#######'),
            'owner_password' => Hash::make('password'),
            'default_language' => 'uk',
            'timezone' => 'Europe/Kyiv',
            'amount_cents' => 100,
            'currency' => 'UAH',
            'expires_at' => now()->addHour(),
        ];
    }
}

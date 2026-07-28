<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Event;
use App\Models\EventOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EventOrder>
 */
class EventOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = Str::random(64);

        return [
            'account_id' => Account::factory(),
            'event_id' => Event::factory(),
            'order_id' => 'EV-'.Str::upper(Str::random(20)),
            'status' => 'pending',
            'buyer_name' => fake()->name(),
            'buyer_email' => fake()->safeEmail(),
            'locale' => 'uk',
            'amount_cents' => 50000,
            'currency' => 'UAH',
            'access_token_encrypted' => $token,
            'access_token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(30),
            'terms_accepted_at' => now(),
            'terms_hash' => hash('sha256', 'test'),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\FestivalEdition;
use App\Models\FestivalTicketOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FestivalTicketOrder> */
class FestivalTicketOrderFactory extends Factory
{
    protected $model = FestivalTicketOrder::class;

    public function definition(): array
    {
        $accessToken = Str::random(64);

        return ['account_id' => fn (array $attributes) => FestivalEdition::findOrFail($attributes['festival_edition_id'])->account_id, 'festival_edition_id' => FestivalEdition::factory(), 'order_id' => 'FTO-'.Str::upper(Str::random(12)), 'status' => 'pending', 'buyer_name' => fake()->name(), 'buyer_email' => fake()->safeEmail(), 'locale' => 'uk', 'amount_cents' => 0, 'currency' => 'UAH', 'access_token_encrypted' => $accessToken, 'access_token_hash' => hash('sha256', $accessToken), 'expires_at' => now()->addMinutes(30), 'terms_accepted_at' => now(), 'terms_hash' => hash('sha256', 'festival-ticket-terms-v1')];
    }
}

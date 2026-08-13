<?php

namespace Database\Factories;

use App\Models\FestivalStreamEntitlement;
use App\Models\FestivalStreamIpLease;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FestivalStreamIpLease>
 */
class FestivalStreamIpLeaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => fn (array $attributes) => FestivalStreamEntitlement::query()->findOrFail($attributes['festival_stream_entitlement_id'])->account_id,
            'festival_stream_entitlement_id' => FestivalStreamEntitlement::factory(),
            'ip_hash' => hash('sha256', fake()->ipv6()),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'expires_at' => now()->addMinutes(2),
        ];
    }
}

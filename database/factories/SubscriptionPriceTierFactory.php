<?php

namespace Database\Factories;

use App\Models\SubscriptionPriceTier;
use App\Models\SubscriptionPriceVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionPriceTier>
 */
class SubscriptionPriceTierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_price_version_id' => SubscriptionPriceVersion::factory(),
            'starts_at_location' => 1,
            'ends_at_location' => null,
            'unit_price_cents' => 90_000,
        ];
    }
}

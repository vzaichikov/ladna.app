<?php

namespace Database\Factories;

use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPriceTier;
use App\Models\SubscriptionPriceVersion;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionPriceVersion>
 */
class SubscriptionPriceVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_plan_id' => SubscriptionPlan::factory(),
            'version' => fake()->unique()->numberBetween(1, 1_000_000),
            'status' => 'draft',
            'currency' => 'UAH',
            'trial_days' => 30,
            'annual_discount_percent' => 10,
            'effective_at' => null,
            'published_at' => null,
            'retired_at' => null,
        ];
    }

    public function published(?CarbonInterface $effectiveAt = null): static
    {
        return $this->afterCreating(function (SubscriptionPriceVersion $priceVersion) use ($effectiveAt): void {
            if (! $priceVersion->tiers()->exists()) {
                SubscriptionPriceTier::factory()->for($priceVersion, 'priceVersion')->create([
                    'starts_at_location' => 1,
                    'ends_at_location' => 1,
                    'unit_price_cents' => 90_000,
                ]);
                SubscriptionPriceTier::factory()->for($priceVersion, 'priceVersion')->create([
                    'starts_at_location' => 2,
                    'ends_at_location' => null,
                    'unit_price_cents' => 80_000,
                ]);
            }

            $priceVersion->publish($effectiveAt ?? now()->subDay());
        });
    }
}

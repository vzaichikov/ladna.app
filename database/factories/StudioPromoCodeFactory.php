<?php

namespace Database\Factories;

use App\Enums\PromoCodeDiscountType;
use App\Models\Account;
use App\Models\StudioPromoCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudioPromoCode>
 */
class StudioPromoCodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->words(3, true),
            'code' => strtoupper(fake()->unique()->bothify('PROMO-####')),
            'discount_type' => PromoCodeDiscountType::Percent,
            'discount_value' => 10,
            'currency' => 'UAH',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'max_total_uses' => null,
            'max_uses_per_identity' => 1,
            'is_active' => true,
        ];
    }
}

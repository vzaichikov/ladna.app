<?php

namespace Database\Factories;

use App\Enums\PromoCodeDiscountType;
use App\Models\FestivalEdition;
use App\Models\FestivalPromoCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FestivalPromoCode> */
class FestivalPromoCodeFactory extends Factory
{
    protected $model = FestivalPromoCode::class;

    public function definition(): array
    {
        return [
            'account_id' => fn (array $attributes) => FestivalEdition::findOrFail($attributes['festival_edition_id'])->account_id,
            'festival_edition_id' => FestivalEdition::factory(),
            'name' => fake()->words(3, true),
            'code' => Str::upper(fake()->unique()->bothify('FEST-####')),
            'discount_type' => PromoCodeDiscountType::Percent,
            'discount_value' => 10,
            'currency' => fn (array $attributes) => FestivalEdition::findOrFail($attributes['festival_edition_id'])->account->default_currency,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'total_usage_limit' => null,
            'per_identity_usage_limit' => 1,
            'is_active' => true,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Enums\PromoCodeDiscountType;
use App\Models\Account;
use App\Models\Event;
use App\Models\EventPromoCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EventPromoCode>
 */
class EventPromoCodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'event_id' => Event::factory(),
            'name' => fake()->words(2, true),
            'code' => Str::upper(fake()->unique()->bothify('EVENT-####-????')),
            'discount_type' => PromoCodeDiscountType::Percent,
            'discount_value' => 10,
            'currency' => 'UAH',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addWeek(),
            'max_total_uses' => null,
            'max_uses_per_identity' => 1,
            'is_active' => true,
        ];
    }
}

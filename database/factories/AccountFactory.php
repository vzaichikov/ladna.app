<?php

namespace Database\Factories;

use App\Enums\AccountMode;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'status' => 'active',
            'mode' => AccountMode::Live->value,
            'default_language' => fake()->randomElement(['uk', 'en']),
            'country_code' => 'UA',
            'default_currency' => fake()->randomElement(['UAH', 'USD', 'EUR']),
            'brand_color' => fake()->hexColor(),
            'studio_slogan' => null,
            'timezone' => 'Europe/Kyiv',
            'legal_entity_name' => null,
            'tax_id' => null,
            'support_instagram_url' => null,
            'support_telegram_url' => null,
            'support_viber_url' => null,
            'support_whatsapp_url' => null,
            'support_phone_url' => null,
            'support_secondary_phone_url' => null,
            'allow_rtsp_cameras' => false,
            'enable_people_counter' => false,
            'enable_telegram_alerts' => true,
            'enable_customer_notifications' => false,
        ];
    }

    public function demoReadonly(): static
    {
        return $this->state(fn (array $attributes): array => [
            'mode' => AccountMode::DemoReadonly->value,
        ]);
    }
}

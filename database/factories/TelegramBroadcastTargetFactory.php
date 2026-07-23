<?php

namespace Database\Factories;

use App\Models\TelegramBotInstallation;
use App\Models\TelegramBroadcastTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramBroadcastTarget>
 */
class TelegramBroadcastTargetFactory extends Factory
{
    protected $model = TelegramBroadcastTarget::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'telegram_bot_installation_id' => TelegramBotInstallation::factory()->platformOwner(),
            'purpose' => TelegramBroadcastTarget::PurposeLadnaFounders,
            'telegram_chat_id' => '-'.fake()->unique()->numberBetween(1000000000, 9999999999),
            'title' => 'Ladna Founders',
            'chat_type' => 'group',
            'is_enabled' => true,
            'verified_at' => now(),
        ];
    }
}

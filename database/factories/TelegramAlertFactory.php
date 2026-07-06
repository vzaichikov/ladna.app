<?php

namespace Database\Factories;

use App\Enums\TelegramAlertRecipientKind;
use App\Enums\TelegramAlertStatus;
use App\Enums\TelegramAlertType;
use App\Models\Account;
use App\Models\TelegramAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramAlert>
 */
class TelegramAlertFactory extends Factory
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
            'trainer_id' => null,
            'scheduled_class_id' => null,
            'class_booking_id' => null,
            'telegram_bot_installation_id' => null,
            'telegram_chat_authorization_id' => null,
            'type' => TelegramAlertType::TrainerAssignment->value,
            'status' => TelegramAlertStatus::Pending->value,
            'recipient_kind' => TelegramAlertRecipientKind::Trainer->value,
            'dedupe_key' => null,
            'telegram_chat_id' => null,
            'telegram_message_id' => null,
            'telegram_user_id' => null,
            'text' => fake()->sentence(),
            'payload' => [],
            'attempts' => 0,
            'next_attempt_at' => null,
            'sent_at' => null,
            'failed_at' => null,
            'last_error' => null,
        ];
    }
}

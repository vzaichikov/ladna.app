<?php

namespace Database\Factories;

use App\Enums\EmailDeliveryStatus;
use App\Enums\EmailRecipientKind;
use App\Enums\EmailScenario;
use App\Models\Account;
use App\Models\EmailDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailDelivery>
 */
class EmailDeliveryFactory extends Factory
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
            'scenario' => EmailScenario::BookingCreated->value,
            'status' => EmailDeliveryStatus::Pending->value,
            'recipient_kind' => EmailRecipientKind::Customer->value,
            'recipient_name' => fake()->name(),
            'recipient_email' => fake()->safeEmail(),
            'locale' => 'en',
            'account_timezone' => 'Europe/Kyiv',
            'subject_key' => EmailScenario::BookingCreated->subjectKey(),
            'subject_parameters' => [
                'class' => 'Pole Beginner',
                'studio' => 'Ladna Studio',
            ],
            'content_view' => EmailScenario::BookingCreated->contentView(),
            'payload' => [
                'account_name' => 'Ladna Studio',
                'recipient_name' => 'Customer',
            ],
            'queued_at' => now(),
        ];
    }
}

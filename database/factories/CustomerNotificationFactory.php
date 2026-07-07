<?php

namespace Database\Factories;

use App\Enums\CustomerNotificationChannel;
use App\Enums\CustomerNotificationRecipientKind;
use App\Enums\CustomerNotificationStatus;
use App\Enums\CustomerNotificationType;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\ScheduledClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerNotification>
 */
class CustomerNotificationFactory extends Factory
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
            'customer_id' => Customer::factory(),
            'scheduled_class_id' => ScheduledClass::factory(),
            'class_booking_id' => ClassBooking::factory(),
            'channel' => CustomerNotificationChannel::Sms->value,
            'type' => CustomerNotificationType::ClassReminder->value,
            'status' => CustomerNotificationStatus::Pending->value,
            'recipient_kind' => CustomerNotificationRecipientKind::Customer->value,
            'recipient_name' => fake()->name(),
            'recipient_phone' => '+380'.fake()->numerify('#########'),
            'text' => fake()->sentence(),
            'payload' => [],
            'attempts' => 0,
            'scheduled_send_at' => now(),
        ];
    }
}

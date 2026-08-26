<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassBookingPaymentWaiver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassBookingPaymentWaiver>
 */
class ClassBookingPaymentWaiverFactory extends Factory
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
            'class_booking_id' => null,
            'customer_class_pass_id' => null,
            'payment_due_kind' => ClassBooking::ManualPaymentDueRoomRental,
            'amount_cents' => null,
            'currency' => 'UAH',
            'customer_name' => fake()->name(),
            'scheduled_class_title' => fake()->words(3, true),
            'scheduled_class_starts_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'scheduled_class_timezone' => 'Europe/Kyiv',
            'location_name' => fake()->city(),
            'room_name' => fake()->word(),
            'customer_class_pass_code' => null,
            'reason' => fake()->sentence(),
            'waived_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'waived_by_actor_user_id' => null,
            'waived_by_actor_trainer_id' => null,
            'waived_by_actor_name' => fake()->name(),
            'waived_by_actor_email' => fake()->safeEmail(),
            'waived_by_actor_role' => 'owner',
            'unwaived_at' => null,
            'unwaive_reason' => null,
            'unwaived_by_actor_user_id' => null,
            'unwaived_by_actor_trainer_id' => null,
            'unwaived_by_actor_name' => null,
            'unwaived_by_actor_email' => null,
            'unwaived_by_actor_role' => null,
        ];
    }

    public function unwaived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'unwaived_at' => now(),
            'unwaive_reason' => fake()->sentence(),
            'unwaived_by_actor_name' => fake()->name(),
            'unwaived_by_actor_email' => fake()->safeEmail(),
            'unwaived_by_actor_role' => 'owner',
        ]);
    }
}

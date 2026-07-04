<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassBookingCorrection;
use App\Models\ScheduledClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassBookingCorrection>
 */
class ClassBookingCorrectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $account = Account::factory();
        $scheduledClass = ScheduledClass::factory()->for($account);

        return [
            'account_id' => $account,
            'scheduled_class_id' => $scheduledClass,
            'class_booking_id' => ClassBooking::factory()->for($account)->for($scheduledClass),
            'action' => ClassBookingCorrection::ActionAdded,
            'pass_effect' => ClassBookingCorrection::PassEffectNoMatchingPass,
            'actor_name' => fake()->name(),
            'actor_email' => fake()->safeEmail(),
            'actor_role' => 'owner',
            'reason' => fake()->sentence(),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Enums\ClassBookingStatus;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\Customer;
use App\Models\ScheduledClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassBooking>
 */
class ClassBookingFactory extends Factory
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
            'scheduled_class_id' => ScheduledClass::factory(),
            'customer_id' => Customer::factory(),
            'booked_by_user_id' => User::factory(),
            'status' => ClassBookingStatus::Booked->value,
            'attended_at' => null,
            'notes' => null,
        ];
    }
}

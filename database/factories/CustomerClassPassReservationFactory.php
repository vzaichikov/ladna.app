<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassReservation;
use App\Models\ScheduledClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerClassPassReservation>
 */
class CustomerClassPassReservationFactory extends Factory
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
            'customer_class_pass_id' => CustomerClassPass::factory(),
            'class_booking_id' => ClassBooking::factory(),
            'scheduled_class_id' => ScheduledClass::factory(),
            'status' => 'reserved',
            'reserved_at' => now(),
            'used_at' => null,
            'released_at' => null,
        ];
    }
}

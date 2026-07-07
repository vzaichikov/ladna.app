<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\CustomerNotificationSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerNotificationSetting>
 */
class CustomerNotificationSettingFactory extends Factory
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
            'is_enabled' => false,
            'class_reminder_enabled' => false,
            'class_reminder_hours_before' => 5,
        ];
    }
}

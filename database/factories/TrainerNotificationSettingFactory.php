<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\TrainerNotificationSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainerNotificationSetting>
 */
class TrainerNotificationSettingFactory extends Factory
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
            'trainer_assignment_enabled' => true,
        ];
    }
}

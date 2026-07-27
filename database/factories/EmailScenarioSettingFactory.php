<?php

namespace Database\Factories;

use App\Enums\EmailScenario;
use App\Models\EmailScenarioSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailScenarioSetting>
 */
class EmailScenarioSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scenario' => fake()->randomElement(EmailScenario::cases())->value,
            'is_enabled' => true,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\TelegramAuthorizationSelection;
use App\Models\TelegramAuthorizationSelectionCandidate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramAuthorizationSelectionCandidate>
 */
class TelegramAuthorizationSelectionCandidateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'telegram_authorization_selection_id' => TelegramAuthorizationSelection::factory(),
            'account_id' => Account::factory(),
            'user_id' => User::factory(),
            'trainer_id' => null,
            'label' => fake()->company(),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Enums\AiProvider;
use App\Models\Account;
use App\Models\AiProviderRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiProviderRequest>
 */
class AiProviderRequestFactory extends Factory
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
            'user_id' => User::factory(),
            'channel' => 'dashboard_chat',
            'provider' => AiProvider::OpenAiApiKey->value,
            'model' => 'gpt-5.5',
            'request_type' => AiProviderRequest::TypeInference,
            'status' => AiProviderRequest::StatusSucceeded,
            'input_tokens' => 100,
            'cached_input_tokens' => 0,
            'output_tokens' => 30,
            'reasoning_tokens' => 0,
            'total_tokens' => 130,
            'duration_ms' => 500,
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}

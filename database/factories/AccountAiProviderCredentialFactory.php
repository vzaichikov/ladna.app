<?php

namespace Database\Factories;

use App\Enums\AiProvider;
use App\Models\Account;
use App\Models\AccountAiProviderCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountAiProviderCredential>
 */
class AccountAiProviderCredentialFactory extends Factory
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
            'provider' => AiProvider::OpenAiApiKey->value,
            'model' => 'gpt-4.1-mini',
            'credentials' => ['api_key' => 'test-api-key'],
            'is_configured' => true,
            'last_validated_at' => null,
        ];
    }
}

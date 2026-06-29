<?php

namespace Database\Factories;

use App\Enums\AiProvider;
use App\Models\PlatformAiProviderCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformAiProviderCredential>
 */
class PlatformAiProviderCredentialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => AiProvider::OllamaCloud->value,
            'model' => 'gemma3:27b-cloud',
            'credentials' => ['api_key' => 'test-ollama-key'],
            'is_configured' => true,
            'last_validated_at' => null,
        ];
    }
}

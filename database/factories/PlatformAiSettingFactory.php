<?php

namespace Database\Factories;

use App\Enums\AiProvider;
use App\Models\PlatformAiSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformAiSetting>
 */
class PlatformAiSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_ai_assistant_enabled' => true,
            'active_provider' => AiProvider::OllamaCloud->value,
            'active_model' => 'gemma3:27b-cloud',
            'bot_display_name' => 'Ladna assistant',
            'internal_instructions' => 'Answer briefly.',
        ];
    }
}

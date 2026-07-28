<?php

namespace Database\Factories;

use App\Models\AiConversationMessage;
use App\Models\AiConversationMessageAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiConversationMessageAttachment>
 */
class AiConversationMessageAttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_conversation_message_id' => AiConversationMessage::factory(),
            'account_id' => fn (array $attributes): int => (int) AiConversationMessage::query()
                ->findOrFail($attributes['ai_conversation_message_id'])
                ->account_id,
            'source' => 'dashboard',
            'disk' => 'local',
            'path' => 'ai-conversation-images/'.fake()->uuid().'.jpg',
            'original_name' => 'assistant-image.png',
            'mime_type' => 'image/jpeg',
            'byte_size' => 1024,
            'width' => 800,
            'height' => 600,
        ];
    }
}

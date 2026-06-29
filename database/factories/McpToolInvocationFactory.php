<?php

namespace Database\Factories;

use App\Enums\AccountApiTokenAbility;
use App\Enums\McpToolInvocationStatus;
use App\Models\Account;
use App\Models\AccountApiToken;
use App\Models\McpToolInvocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<McpToolInvocation>
 */
class McpToolInvocationFactory extends Factory
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
            'account_api_token_id' => AccountApiToken::factory(),
            'ai_conversation_id' => null,
            'ai_conversation_message_id' => null,
            'tool_name' => 'get-studio-profile',
            'required_ability' => AccountApiTokenAbility::McpRead->value,
            'status' => McpToolInvocationStatus::Succeeded->value,
            'input' => [],
            'output' => ['ok' => true],
            'error_message' => null,
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}

<?php

namespace App\Models;

use App\Enums\McpToolInvocationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'account_api_token_id', 'ai_conversation_id', 'ai_conversation_message_id', 'tool_name', 'required_ability', 'status', 'input', 'output', 'error_message', 'started_at', 'finished_at'])]
class McpToolInvocation extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => McpToolInvocationStatus::class,
            'input' => 'array',
            'output' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function accountApiToken(): BelongsTo
    {
        return $this->belongsTo(AccountApiToken::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    public function conversationMessage(): BelongsTo
    {
        return $this->belongsTo(AiConversationMessage::class, 'ai_conversation_message_id');
    }
}
